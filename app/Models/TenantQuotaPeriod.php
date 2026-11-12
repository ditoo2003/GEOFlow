<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 租户月度对账快照：该月套餐额度、用量与上月超用结转。
 *
 * 只由 {@see \App\Services\Billing\TenantQuotaReconciliationService} 写入。
 */
class TenantQuotaPeriod extends Model
{
    protected $table = 'tenant_quota_periods';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'site_limit' => 'integer',
            'article_quota' => 'integer',
            'token_budget' => 'integer',
            'article_used' => 'integer',
            'article_carryover_in' => 'integer',
            'token_used' => 'integer',
            'token_carryover_in' => 'integer',
            'finalized_at' => 'datetime',
        ];
    }
}
