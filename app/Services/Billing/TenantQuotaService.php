<?php

namespace App\Services\Billing;

use App\Exceptions\ApiException;
use App\Models\Tenant;
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
 * 额度口径（见 {@see TenantQuotaReconciliationService}）：本月可用额度 = 套餐额度 - 上月超用结转；
 * 未用完额度月度归零。租户暂停/归档时拒绝新发起，归档租户余额冻结。
 *
 * 租户解析要求显式：租户上下文直接返回；系统上下文（超管/CLI）取后台已选租户，
 * 解析不到时抛中文异常，不隐式回落默认租户。
 */
final class TenantQuotaService
{
    public function __construct(
        private readonly TenantPlanCatalog $plans,
        private readonly TenantQuotaReconciliationService $reconciliation,
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
        return $this->reconciliation->publishedCountForPeriod($tenantId, $this->currentPeriod());
    }

    /** 本月 AI 调用的 token 成本（内部护栏，不对外计费）。 */
    public function monthlyTokenUsage(int $tenantId): int
    {
        return $this->reconciliation->tokenUsageForPeriod($tenantId, $this->currentPeriod());
    }

    public function assertCanCreateSite(): void
    {
        $tenantId = $this->resolveTenantId()
            ?? throw new DomainException('当前无法确定租户，请先在后台选择租户后再创建站点。');

        $this->assertTenantOperational($tenantId, 'site');

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

        $this->assertTenantOperational($tenantId, 'generation');

        $report = $this->reconciliation->ensurePeriod($tenantId, $this->currentPeriod());

        app(TenantQuotaAlertService::class)->evaluate($tenantId);

        $quota = (int) $report['article_quota'];
        if ($quota > 0) {
            $effective = (int) $report['article_effective_quota'];
            $used = (int) $report['article_used'];
            $carryover = (int) $report['article_carryover_in'];

            if ($used >= $effective) {
                throw new ApiException('tenant_quota_exceeded', $carryover > 0
                    ? sprintf(
                        '本月可用文章额度已用完（套餐 %d 篇，抵扣上月超用结转 %d 篇），新的生成任务已暂停，下月自动恢复；已运行任务不受影响。',
                        $quota,
                        $carryover,
                    )
                    : sprintf(
                        '本月已发布文章数已达套餐上限（%d 篇），新的生成任务已暂停，下月自动恢复；已运行任务不受影响。',
                        $quota,
                    ), 409);
            }
        }

        $budget = (int) $report['token_budget'];
        if ($budget <= 0) {
            return;
        }

        $effectiveBudget = (int) $report['token_effective_budget'];
        $ratio = (float) config('billing.block_ratio', 1.0);
        if ($ratio > 0 && (int) $report['token_used'] >= (int) ceil($effectiveBudget * $ratio)) {
            $carryover = (int) $report['token_carryover_in'];

            throw new ApiException(
                'tenant_quota_token_exceeded',
                $carryover > 0
                    ? sprintf(
                        '本月 AI 成本额度已用完（含上月超用结转 %d token），新的生成任务已暂停，下月自动恢复；已运行任务不受影响。',
                        $carryover,
                    )
                    : '本月 AI 成本额度已用完，新的生成任务已暂停，下月自动恢复；已运行任务不受影响。',
                409,
            );
        }
    }

    /** @return array<string, int> */
    public function summary(int $tenantId): array
    {
        $plan = $this->plans->planFor($tenantId);
        $report = $this->reconciliation->ensurePeriod($tenantId, $this->currentPeriod());

        return [
            'site_count' => $this->siteCount($tenantId),
            'site_limit' => $plan['site_limit'],
            'article_count' => (int) $report['article_used'],
            'article_quota' => (int) $report['article_quota'],
            'article_carryover_in' => (int) $report['article_carryover_in'],
            'article_effective_quota' => (int) $report['article_effective_quota'],
            'article_remaining' => (int) $report['article_remaining'],
            'token_usage' => (int) $report['token_used'],
            'token_budget' => (int) $report['token_budget'],
            'token_carryover_in' => (int) $report['token_carryover_in'],
            'token_effective_budget' => (int) $report['token_effective_budget'],
        ];
    }

    public function currentPeriod(): string
    {
        return Carbon::now(config('app.timezone'))->format('Y-m');
    }

    /**
     * token 成本护栏档位：normal | degrade | block。
     *
     * 只依赖内部用量，不对外报价；预算未配置（<=0）时不启用护栏。
     * 上月 token 超用结转会同步扣减本月护栏预算。
     */
    public function tokenPressure(int $tenantId): string
    {
        $report = $this->reconciliation->ensurePeriod($tenantId, $this->currentPeriod());
        if ((int) $report['token_budget'] <= 0) {
            return 'normal';
        }

        $budget = (int) $report['token_effective_budget'];
        $usage = (int) $report['token_used'];
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
        $report = $this->reconciliation->ensurePeriod($tenantId, $this->currentPeriod());

        if ((int) $report['article_quota'] > 0
            && (int) $report['article_used'] >= (int) $report['article_effective_quota']) {
            return 'block';
        }

        return $this->tokenPressure($tenantId);
    }

    /**
     * 租户状态门：归档租户余额冻结，暂停租户不接新发起；运行中任务不受影响。
     *
     * @param  'generation'|'site'  $mode
     */
    private function assertTenantOperational(int $tenantId, string $mode): void
    {
        $status = Tenant::query()->whereKey($tenantId)->value('status');
        if ($status === null) {
            $message = '租户不存在或已被删除，无法继续操作。';
            $mode === 'site' ? throw new DomainException($message) : throw new ApiException('tenant_missing', $message, 409);
        }

        $status = (string) $status;
        if ($status === 'active') {
            return;
        }

        $archived = $status === 'archived';
        $message = match (true) {
            $mode === 'site' && $archived => '租户已归档，配额已冻结，无法新建站点。',
            $mode === 'site' => '租户已暂停，无法新建站点。',
            $archived => '租户已归档，配额已冻结，无法发起新的生成任务。',
            default => '租户已暂停，新的生成任务已暂停；已运行任务不受影响。',
        };

        $mode === 'site'
            ? throw new DomainException($message)
            : throw new ApiException($archived ? 'tenant_archived' : 'tenant_suspended', $message, 409);
    }
}
