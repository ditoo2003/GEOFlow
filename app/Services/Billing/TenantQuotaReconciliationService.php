<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantUsageEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 租户额度的月度对账（归零 + 超用结转）。
 *
 * 口径（2026-11-13 确认）：
 * - 未用完的额度月度归零，不结转到下月；
 * - 上月超用部分结转到次月作为负额度抵扣，不做额外惩罚——超用是"不在 job 中途杀任务"的必然结果，
 *   成本不由客户承担；本月可用额度 = max(0, 套餐额度 - 结转)。
 * - 跨月按 Asia/Shanghai（`config('app.timezone')`）自然月切分；同一篇计量只归入其入账月份，
 *   不会同时计入两个月（`tenant_usage_events.period` + 文章幂等键保证）。
 * - 额度快照在周期首次访问时写入；已结算周期不随套餐变更回溯改写，保证对账可复核。
 * - 租户暂停期间不产生新计量（见 {@see TenantUsageMeter}）；归档租户余额冻结，
 *   不新建周期快照，只读展示历史账。
 */
final class TenantQuotaReconciliationService
{
    public function __construct(
        private readonly TenantPlanCatalog $plans,
    ) {}

    /**
     * 确保周期快照存在并返回对账结果。
     *
     * @return array{
     *     period:string, plan_code:?string, site_limit:int,
     *     article_quota:int, article_carryover_in:int, article_effective_quota:int,
     *     article_used:int, article_overage:int, article_remaining:int,
     *     token_budget:int, token_carryover_in:int, token_effective_budget:int,
     *     token_used:int, token_overage:int, token_remaining:int,
     *     finalized:bool, frozen:bool
     * }
     */
    public function ensurePeriod(int $tenantId, string $period): array
    {
        $row = $this->row($tenantId, $period);
        if ($row === null) {
            if (! $this->tenantAllowsAccrual($tenantId)) {
                return $this->buildReport($tenantId, $period, null);
            }

            [$articleCarryover, $tokenCarryover] = $this->carryoverInto($tenantId, $period);
            $plan = $this->plans->planFor($tenantId);

            DB::table('tenant_quota_periods')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'period' => $period,
                'plan_code' => $plan['code'],
                'site_limit' => $plan['site_limit'],
                'article_quota' => $plan['article_quota'],
                'token_budget' => $plan['token_budget'],
                'article_used' => 0,
                'article_carryover_in' => $articleCarryover,
                'token_used' => 0,
                'token_carryover_in' => $tokenCarryover,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = $this->row($tenantId, $period);
        }

        return $this->buildReport($tenantId, $period, $row);
    }

    /**
     * 结算指定周期：把当期货量写入快照并标记已结算，同时物化下一周期的结转。
     *
     * @return array<string, mixed>
     */
    public function finalize(int $tenantId, string $period): array
    {
        if (! $this->tenantAllowsAccrual($tenantId)) {
            return $this->ensurePeriod($tenantId, $period);
        }

        $this->ensurePeriod($tenantId, $period);

        DB::table('tenant_quota_periods')
            ->where('tenant_id', $tenantId)
            ->where('period', $period)
            ->whereNull('finalized_at')
            ->update([
                'article_used' => $this->publishedCountForPeriod($tenantId, $period),
                'token_used' => $this->tokenUsageForPeriod($tenantId, $period),
                'finalized_at' => now(),
                'updated_at' => now(),
            ]);

        $next = $this->shiftPeriod($period, 1);
        if ($next <= $this->currentPeriod()) {
            $this->ensurePeriod($tenantId, $next);
        }

        return $this->ensurePeriod($tenantId, $period);
    }

    /**
     * 最近若干周期的对账结果（含当前周期）。
     *
     * @return list<array<string, mixed>>
     */
    public function recentPeriods(int $tenantId, int $limit = 6): array
    {
        $this->ensurePeriod($tenantId, $this->currentPeriod());

        return DB::table('tenant_quota_periods')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('period')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (object $row): array => $this->buildReport($tenantId, (string) $row->period, $row))
            ->all();
    }

    public function currentPeriod(): string
    {
        return Carbon::now(config('app.timezone'))->format('Y-m');
    }

    public function publishedCountForPeriod(int $tenantId, string $period): int
    {
        return (int) DB::table('tenant_usage_events')
            ->where('tenant_id', $tenantId)
            ->where('meter', TenantUsageEvent::METER_PUBLISHED_ARTICLES)
            ->where('period', $period)
            ->sum('quantity');
    }

    public function tokenUsageForPeriod(int $tenantId, string $period): int
    {
        $start = $this->periodStart($period);

        return (int) DB::table('ai_model_usage_events')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->copy()->addMonthNoOverflow())
            ->sum('total_tokens');
    }

    /** 租户是否仍可产生新计量：只有 active 租户；暂停/归档期间冻结。 */
    public function tenantAllowsAccrual(int $tenantId): bool
    {
        $status = Tenant::query()->whereKey($tenantId)->value('status');

        return $status === null || (string) $status === 'active';
    }

    /** @return array{0: int, 1: int} 文章篇数与 token 的上月超用结转 */
    private function carryoverInto(int $tenantId, string $period): array
    {
        $previous = $this->shiftPeriod($period, -1);
        $previousRow = $this->row($tenantId, $previous);

        if ($previousRow === null) {
            $plan = $this->plans->planFor($tenantId);
            DB::table('tenant_quota_periods')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'period' => $previous,
                'plan_code' => $plan['code'],
                'site_limit' => $plan['site_limit'],
                'article_quota' => $plan['article_quota'],
                'token_budget' => $plan['token_budget'],
                'article_used' => 0,
                'article_carryover_in' => 0,
                'token_used' => 0,
                'token_carryover_in' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $previousRow = $this->row($tenantId, $previous);
        }

        $articleUsed = $this->publishedCountForPeriod($tenantId, $previous);
        $tokenUsed = $this->tokenUsageForPeriod($tenantId, $previous);

        DB::table('tenant_quota_periods')
            ->where('tenant_id', $tenantId)
            ->where('period', $previous)
            ->whereNull('finalized_at')
            ->update([
                'article_used' => $articleUsed,
                'token_used' => $tokenUsed,
                'finalized_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            max(0, $articleUsed - (int) ($previousRow->article_quota ?? 0)),
            max(0, $tokenUsed - (int) ($previousRow->token_budget ?? 0)),
        ];
    }

    /** @return array<string, mixed> */
    private function buildReport(int $tenantId, string $period, ?object $row): array
    {
        $articleUsed = $this->publishedCountForPeriod($tenantId, $period);
        $tokenUsed = $this->tokenUsageForPeriod($tenantId, $period);

        $articleQuota = (int) ($row->article_quota ?? 0);
        $articleCarryover = (int) ($row->article_carryover_in ?? 0);
        $articleEffective = $articleQuota > 0 ? max(0, $articleQuota - $articleCarryover) : 0;

        $tokenBudget = (int) ($row->token_budget ?? 0);
        $tokenCarryover = (int) ($row->token_carryover_in ?? 0);
        $tokenEffective = $tokenBudget > 0 ? max(0, $tokenBudget - $tokenCarryover) : 0;

        return [
            'period' => $period,
            'plan_code' => $row->plan_code ?? null,
            'site_limit' => (int) ($row->site_limit ?? 0),
            'article_quota' => $articleQuota,
            'article_carryover_in' => $articleCarryover,
            'article_effective_quota' => $articleEffective,
            'article_used' => $articleUsed,
            'article_overage' => $articleQuota > 0 ? max(0, $articleUsed - $articleQuota) : 0,
            'article_remaining' => $articleQuota > 0 ? max(0, $articleEffective - $articleUsed) : 0,
            'token_budget' => $tokenBudget,
            'token_carryover_in' => $tokenCarryover,
            'token_effective_budget' => $tokenEffective,
            'token_used' => $tokenUsed,
            'token_overage' => $tokenBudget > 0 ? max(0, $tokenUsed - $tokenBudget) : 0,
            'token_remaining' => $tokenBudget > 0 ? max(0, $tokenEffective - $tokenUsed) : 0,
            'finalized' => $row !== null && $row->finalized_at !== null,
            'frozen' => ! $this->tenantAllowsAccrual($tenantId),
        ];
    }

    private function row(int $tenantId, string $period): ?object
    {
        return DB::table('tenant_quota_periods')
            ->where('tenant_id', $tenantId)
            ->where('period', $period)
            ->first();
    }

    private function periodStart(string $period): Carbon
    {
        return Carbon::createFromFormat('!Y-m', $period, config('app.timezone'))->startOfMonth();
    }

    private function shiftPeriod(string $period, int $months): string
    {
        return $this->periodStart($period)->addMonthsNoOverflow($months)->format('Y-m');
    }
}
