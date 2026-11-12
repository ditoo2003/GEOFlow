<?php

namespace App\Services\Billing;

use App\Exceptions\ApiException;
use App\Models\TenantQuotaAlert;
use App\Models\TenantUsageEvent;
use App\Support\AdminTenantContext;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 租户配额判定。
 *
 * 入口硬限顺序：先判"本月已发布文章篇数"（第一道硬限），再判"本月 token 成本护栏"。
 * 两者谁先触发谁生效；同时命中时按篇数文案展示。只拦截新发起，运行中 job 一律不杀。
 *
 * 租户解析要求显式：租户上下文直接返回；系统上下文（超管/CLI）取后台已选租户，
 * 解析不到时抛中文异常，不隐式回落默认租户。
 */
final class TenantQuotaService
{
    public function __construct(
        private readonly TenantPlanCatalog $plans,
    ) {}

    public function resolveTenantId(): ?int
    {
        $context = app(TenantContext::class);
        $tenantId = $context->tenantId();
        if ($tenantId !== null) {
            return $tenantId;
        }

        if (! $context->isSystem()) {
            return null;
        }

        // 只有后台 HTTP 请求才有会话级租户选择；CLI/队列根上下文保持显式，不猜默认租户。
        if (! app()->bound('session') || ! app('session')->isStarted()) {
            return null;
        }

        return AdminTenantContext::selectedTenantId();
    }

    public function quotaTenantId(): int
    {
        return $this->resolveTenantId()
            ?? throw new DomainException('当前无法确定租户，请先在后台选择租户后再操作。');
    }

    /**
     * 系统上下文（平台超管 / API Token）下，已有实体（任务）的租户归属就是显式事实，
     * 按实体归属计量而不是回落默认租户；无实体可依据时仍要求显式选择租户。
     */
    private function explicitTargetTenantId(?int $targetTenantId): ?int
    {
        return is_int($targetTenantId) && $targetTenantId > 0 ? $targetTenantId : null;
    }

    /** 只统计该租户下未归档的托管站点；全局主站不属于任何租户，天然不计入。 */
    public function siteCount(int $tenantId): int
    {
        return DB::table('hosted_site_profiles')
            ->where('tenant_id', $tenantId)
            ->whereNull('archived_at')
            ->count();
    }

    /** 本月通过质检并发布的文章篇数（计量账本按文章幂等，多站点分发不重复计数）。 */
    public function monthlyPublishedCount(int $tenantId): int
    {
        return (int) DB::table('tenant_usage_events')
            ->where('tenant_id', $tenantId)
            ->where('meter', TenantUsageEvent::METER_PUBLISHED_ARTICLES)
            ->where('period', $this->currentPeriod())
            ->sum('quantity');
    }

    /** 本月 AI 调用的 token 成本（内部护栏，不对外计费）。 */
    public function monthlyTokenUsage(int $tenantId): int
    {
        return (int) DB::table('ai_model_usage_events')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('total_tokens');
    }

    public function assertCanCreateSite(): void
    {
        $tenantId = $this->resolveTenantId()
            ?? throw new DomainException('当前无法确定租户，请先在后台选择租户后再创建站点。');

        $limit = $this->plans->siteLimit($tenantId);
        if ($limit <= 0) {
            return;
        }

        if ($this->siteCount($tenantId) >= $limit) {
            throw new DomainException(sprintf(
                '当前套餐站点数已达上限（%d 个），请升级套餐或归档旧站点。',
                $limit,
            ));
        }
    }

    public function assertCanStartGeneration(?int $targetTenantId = null): void
    {
        $tenantId = $this->resolveTenantId() ?? $this->explicitTargetTenantId($targetTenantId);
        if ($tenantId === null) {
            throw new ApiException(
                'tenant_quota_tenant_unresolved',
                '当前无法确定租户，请先在后台选择租户后再发起生成任务。',
                409,
            );
        }

        app(TenantQuotaAlertService::class)->evaluate($tenantId);

        $quota = $this->plans->articleQuota($tenantId);
        if ($quota > 0 && $this->monthlyPublishedCount($tenantId) >= $quota) {
            throw new ApiException('tenant_quota_exceeded', sprintf(
                '本月已发布文章数已达套餐上限（%d 篇），新的生成任务已暂停，下月自动恢复；已运行任务不受影响。',
                $quota,
            ), 409);
        }

        $budget = $this->plans->tokenBudget($tenantId);
        if ($budget <= 0) {
            return;
        }

        $ratio = (float) config('billing.block_ratio', 1.0);
        if ($ratio > 0 && $this->monthlyTokenUsage($tenantId) >= (int) ceil($budget * $ratio)) {
            throw new ApiException(
                'tenant_quota_token_exceeded',
                '本月 AI 成本额度已用完，新的生成任务已暂停，下月自动恢复；已运行任务不受影响。',
                409,
            );
        }
    }

    /** @return array{site_count:int, site_limit:int, article_count:int, article_quota:int, token_usage:int, token_budget:int} */
    public function summary(int $tenantId): array
    {
        $plan = $this->plans->planFor($tenantId);

        return [
            'site_count' => $this->siteCount($tenantId),
            'site_limit' => $plan['site_limit'],
            'article_count' => $this->monthlyPublishedCount($tenantId),
            'article_quota' => $plan['article_quota'],
            'token_usage' => $this->monthlyTokenUsage($tenantId),
            'token_budget' => $plan['token_budget'],
        ];
    }

    public function currentPeriod(): string
    {
        return Carbon::now()->format('Y-m');
    }

    /**
     * token 成本护栏档位：normal | degrade | block。
     *
     * 只依赖内部用量，不对外报价；预算未配置（<=0）时不启用护栏。
     */
    public function tokenPressure(int $tenantId): string
    {
        $budget = $this->plans->tokenBudget($tenantId);
        if ($budget <= 0) {
            return 'normal';
        }

        $usage = $this->monthlyTokenUsage($tenantId);
        $blockRatio = (float) config('billing.block_ratio', 1.0);
        $degradeRatio = (float) config('billing.degrade_ratio', 0.8);

        if ($blockRatio > 0 && $usage >= (int) ceil($budget * $blockRatio)) {
            return 'block';
        }

        if ($degradeRatio > 0 && $usage >= (int) ceil($budget * $degradeRatio)) {
            return 'degrade';
        }

        return 'normal';
    }

    /** 综合档位：篇数或 token 任一耗尽即 block；token 达降级线为 degrade。 */
    public function pressure(int $tenantId): string
    {
        $quota = $this->plans->articleQuota($tenantId);
        if ($quota > 0 && $this->monthlyPublishedCount($tenantId) >= $quota) {
            return 'block';
        }

        return $this->tokenPressure($tenantId);
    }
}
