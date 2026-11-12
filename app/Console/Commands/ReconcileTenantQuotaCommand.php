<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Services\Billing\TenantQuotaReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * 租户额度月度对账。
 *
 * 结算指定自然月（Asia/Shanghai）：把当期货量写入周期快照并标记结算，
 * 同时把超用部分物化为下一周期的抵扣额度。重复执行幂等，不会重复扣减。
 */
final class ReconcileTenantQuotaCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:reconcile-tenant-quota
        {--period= : 结算月份（YYYY-MM），缺省为上一自然月}
        {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Reconcile tenant monthly quotas (no rollover of unused quota, overage carried forward as credit)';

    public function handle(TenantQuotaReconciliationService $reconciliation): int
    {
        $period = $this->resolvePeriod();
        if ($period === null) {
            return self::INVALID;
        }

        $rows = [];
        $exitCode = $this->runForEachTenant(function (int $tenantId) use ($reconciliation, $period, &$rows): int {
            $report = $reconciliation->finalize($tenantId, $period);

            $rows[] = [
                '#'.$tenantId,
                $period,
                (string) ($report['plan_code'] ?? '—'),
                number_format((int) $report['article_quota']),
                number_format((int) $report['article_used']),
                number_format((int) $report['article_carryover_in']),
                number_format((int) $report['article_effective_quota']),
                number_format((int) $report['token_budget']),
                number_format((int) $report['token_used']),
                number_format((int) $report['token_carryover_in']),
                $report['frozen'] ? '已冻结' : ($report['finalized'] ? '已结算' : '未结算'),
            ];

            return self::SUCCESS;
        });

        if ($rows !== []) {
            $this->table(
                ['租户', '周期', '套餐', '篇数额度', '已发布', '篇数结转', '可用额度', 'token 预算', 'token 用量', 'token 结转', '状态'],
                $rows,
            );
            $this->line('口径：未用额度月度归零；上月超用结转到次月抵扣；跨月按 Asia/Shanghai 自然月。');
        }

        return $exitCode;
    }

    private function resolvePeriod(): ?string
    {
        $now = Carbon::now(config('app.timezone'));
        $value = trim((string) ($this->option('period') ?? ''));

        if ($value === '') {
            return $now->copy()->subMonthNoOverflow()->format('Y-m');
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            $this->components->error('period 参数无效，请传入 YYYY-MM（例如 2026-10）。');

            return null;
        }

        if ($value > $now->format('Y-m')) {
            $this->components->error('不能结算未来月份。');

            return null;
        }

        return $value;
    }
}
