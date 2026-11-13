<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiVisibilitySampleBatch;
use App\Services\Billing\TenantPlanCatalog;
use App\Services\Billing\TenantQuotaAlertService;
use App\Services\Billing\VisibilitySampleUsageMeter;

/**
 * 采样频率闸门：预算越界时"降频 + 告警"，不硬性关闭能力。
 *
 * - 正常：按 `default_daily_samples`（默认 3 次/天）采样；
 * - 月度预算达到 degrade_ratio（默认 80%）：降到 `degraded_daily_samples`（默认 1 次/天）；
 * - 预算用尽：仍保留最低频率，只告警，不返回配额错误。
 */
final class VisibilitySamplingThrottle
{
    public function __construct(
        private readonly TenantPlanCatalog $plans,
        private readonly VisibilitySampleUsageMeter $meter,
        private readonly TenantQuotaAlertService $alerts,
    ) {}

    /**
     * @return array{allowed:bool,degraded:bool,daily_cap:int,reason:?string,used:int,budget:int}
     */
    public function decision(int $tenantId, AiVisibilitySampleBatch $batch): array
    {
        $budget = $this->plans->visibilitySampleQuota($tenantId);
        $used = $this->meter->usedThisPeriod($tenantId);
        $degradeRatio = (float) config('billing.degrade_ratio', 0.8);

        $degraded = $budget > 0 && $degradeRatio > 0 && $used >= (int) ceil($budget * $degradeRatio);
        $normalCap = max(1, (int) config('geoflow.ai_visibility.default_daily_samples', 3));
        $degradedCap = max(1, min($normalCap, (int) config('geoflow.ai_visibility.degraded_daily_samples', 1)));
        $dailyCap = $degraded ? $degradedCap : $normalCap;

        $sampledToday = (int) $batch->completed_samples + (int) $batch->failed_samples;
        $allowed = $sampledToday < $dailyCap;

        $reason = null;
        if ($degraded) {
            $reason = $budget > 0 && $used >= $budget ? 'budget_exhausted' : 'budget_degraded';
            $this->alerts->evaluateVisibility($tenantId, $used, $budget);
        } elseif (! $allowed) {
            $reason = 'daily_cap_reached';
        }

        return [
            'allowed' => $allowed,
            'degraded' => $degraded,
            'daily_cap' => $dailyCap,
            'reason' => $reason,
            'used' => $used,
            'budget' => $budget,
        ];
    }
}
