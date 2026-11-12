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
 * 阈值判断使用对账后的可用额度（套餐额度 - 上月超用结转）。
 */
final class TenantQuotaAlertService
{
    public function __construct(
        private readonly TenantQuotaService $quota,
        private readonly TenantQuotaReconciliationService $reconciliation,
    ) {}

    public function evaluate(int $tenantId): void
    {
        try {
            $period = $this->quota->currentPeriod();
            $report = $this->reconciliation->ensurePeriod($tenantId, $period);

            $articleQuota = (int) $report['article_quota'];
            if ($articleQuota > 0) {
                $published = (int) $report['article_used'];
                $effective = (int) $report['article_effective_quota'];
                $carryover = (int) $report['article_carryover_in'];

                if ($published >= $effective) {
                    $this->raise(
                        $tenantId,
                        $period,
                        TenantQuotaAlert::METER_PUBLISHED_ARTICLES,
                        TenantQuotaAlert::LEVEL_BLOCK,
                        $carryover > 0
                            ? sprintf(
                                '本月已发布文章数已达套餐上限（%d 篇，另抵扣上月超用结转 %d 篇），新的生成任务已暂停，下月自动恢复。',
                                $articleQuota,
                                $carryover,
                            )
                            : sprintf('本月已发布文章数已达套餐上限（%d 篇），新的生成任务已暂停，下月自动恢复。', $articleQuota),
                        [
                            'published' => $published,
                            'article_quota' => $articleQuota,
                            'article_effective_quota' => $effective,
                            'article_carryover_in' => $carryover,
                        ],
                    );
                }
            }

            if ((int) $report['token_budget'] <= 0) {
                return;
            }

            $budget = (int) $report['token_effective_budget'];
            $usage = (int) $report['token_used'];
            $carryover = (int) $report['token_carryover_in'];
            $blockRatio = (float) config('billing.block_ratio', 1.0);
            $degradeRatio = (float) config('billing.degrade_ratio', 0.8);

            if ($blockRatio > 0 && $usage >= (int) ceil($budget * $blockRatio)) {
                $this->raise(
                    $tenantId,
                    $period,
                    TenantQuotaAlert::METER_TOKENS,
                    TenantQuotaAlert::LEVEL_BLOCK,
                    $carryover > 0
                        ? sprintf('本月 AI 成本额度已用完（含上月超用结转 %d token），新的生成任务已暂停，下月自动恢复。', $carryover)
                        : '本月 AI 成本额度已用完，新的生成任务已暂停，下月自动恢复。',
                    ['usage' => $usage, 'token_budget' => $budget, 'token_carryover_in' => $carryover],
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
                    ['usage' => $usage, 'token_budget' => $budget, 'token_carryover_in' => $carryover],
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
