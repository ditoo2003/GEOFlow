<?php

namespace App\Services\Billing;

use App\Models\TenantQuotaAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 配额阈值告警：token 80% 降级、token 100% 拦截、篇数 100% 拦截。
 *
 * 告警写入按 `(tenant_id, period, meter, level)` 幂等，同一周期同一档位只留一条；
 * 任何失败都只记录日志，绝不向调用方抛异常（降级/告警路径不能中断运行中的 job）。
 */
final class TenantQuotaAlertService
{
    public function __construct(
        private readonly TenantPlanCatalog $plans,
        private readonly TenantQuotaService $quota,
    ) {}

    public function evaluate(int $tenantId): void
    {
        try {
            $plan = $this->plans->planFor($tenantId);
            $period = $this->quota->currentPeriod();

            $articleQuota = (int) $plan['article_quota'];
            if ($articleQuota > 0) {
                $published = $this->quota->monthlyPublishedCount($tenantId);
                if ($published >= $articleQuota) {
                    $this->raise(
                        $tenantId,
                        $period,
                        TenantQuotaAlert::METER_PUBLISHED_ARTICLES,
                        TenantQuotaAlert::LEVEL_BLOCK,
                        sprintf('本月已发布文章数已达套餐上限（%d 篇），新的生成任务已暂停，下月自动恢复。', $articleQuota),
                        ['published' => $published, 'article_quota' => $articleQuota],
                    );
                }
            }

            $budget = (int) $plan['token_budget'];
            if ($budget <= 0) {
                return;
            }

            $usage = $this->quota->monthlyTokenUsage($tenantId);
            $blockRatio = (float) config('billing.block_ratio', 1.0);
            $degradeRatio = (float) config('billing.degrade_ratio', 0.8);

            if ($blockRatio > 0 && $usage >= (int) ceil($budget * $blockRatio)) {
                $this->raise(
                    $tenantId,
                    $period,
                    TenantQuotaAlert::METER_TOKENS,
                    TenantQuotaAlert::LEVEL_BLOCK,
                    '本月 AI 成本额度已用完，新的生成任务已暂停，下月自动恢复。',
                    ['usage' => $usage, 'token_budget' => $budget],
                );

                return;
            }

            if ($degradeRatio > 0 && $usage >= (int) ceil($budget * $degradeRatio)) {
                $this->raise(
                    $tenantId,
                    $period,
                    TenantQuotaAlert::METER_TOKENS,
                    TenantQuotaAlert::LEVEL_DEGRADE,
                    '本月 AI 成本额度已使用 80%，已自动降级到套餐配置的低成本模型。',
                    ['usage' => $usage, 'token_budget' => $budget],
                );
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * 降级模型不可用时留痕：不中断 job，只告警并在看板暴露配置问题。
     */
    public function reportDowngradeUnavailable(int $tenantId, int $modelId): void
    {
        try {
            $this->raise(
                $tenantId,
                $this->quota->currentPeriod(),
                TenantQuotaAlert::METER_TOKENS,
                TenantQuotaAlert::LEVEL_DEGRADE_UNAVAILABLE,
                sprintf('套餐配置的降级模型（ID %d）不可用，本月 AI 成本达到降级线后仍使用原模型。', $modelId),
                ['downgrade_model_id' => $modelId],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param array<string, mixed> $context */
    private function raise(int $tenantId, string $period, string $meter, string $level, string $message, array $context): void
    {
        $inserted = DB::table('tenant_quota_alerts')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'period' => $period,
            'meter' => $meter,
            'level' => $level,
            'message' => $message,
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted > 0) {
            Log::warning('tenant_quota.threshold_crossed', [
                'tenant_id' => $tenantId,
                'period' => $period,
                'meter' => $meter,
                'level' => $level,
                'context' => $context,
            ]);
        }
    }
}
