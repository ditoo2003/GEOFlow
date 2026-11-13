<?php

namespace App\Services\Billing;

use App\Models\AiVisibilityRun;
use App\Models\TenantUsageEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 多引擎可见性样本计费账本。
 *
 * - 幂等键固定为 `visibility.sample:{run_uuid}`：同一篇文章的重复回调不会重复计费；
 * - 只有"已完成且有回答"的样本入账（有效样本口径），失败请求不计客户配额；
 * - 月度按 Asia/Shanghai 自然月归档，与文章计量一致。
 */
final class VisibilitySampleUsageMeter
{
    public function __construct(
        private readonly TenantQuotaAlertService $alerts,
        private readonly TenantPlanCatalog $plans,
    ) {}

    public function record(AiVisibilityRun $run, int $tenantId): bool
    {
        $uuid = trim((string) ($run->uuid ?? ''));
        if ($uuid === '' || $tenantId <= 0) {
            return false;
        }

        $occurredAt = now(config('app.timezone'));
        $inserted = DB::table('tenant_usage_events')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'meter' => TenantUsageEvent::METER_VISIBILITY_SAMPLES,
            'quantity' => 1,
            'source_type' => 'ai_visibility_run',
            'source_id' => (string) $run->getKey(),
            'idempotency_key' => 'visibility.sample:'.$uuid,
            'period' => $occurredAt->format('Y-m'),
            'occurred_at' => $occurredAt,
            'metadata' => json_encode([
                'run_id' => (int) $run->getKey(),
                'engine_key' => (string) $run->engine_key,
                'keyword' => (string) $run->keyword,
                'search_enabled' => (bool) $run->search_enabled,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;

        if ($inserted) {
            $this->alerts->evaluateVisibility(
                $tenantId,
                $this->usedThisPeriod($tenantId, $occurredAt),
                $this->plans->visibilitySampleQuota($tenantId),
            );
        }

        return $inserted;
    }

    public function usedThisPeriod(int $tenantId, ?Carbon $at = null): int
    {
        return (int) DB::table('tenant_usage_events')
            ->where('tenant_id', $tenantId)
            ->where('meter', TenantUsageEvent::METER_VISIBILITY_SAMPLES)
            ->where('period', ($at ?? now(config('app.timezone')))->format('Y-m'))
            ->sum('quantity');
    }
}
