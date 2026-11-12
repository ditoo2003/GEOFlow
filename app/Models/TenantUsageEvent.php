<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 租户用量计量账本（对外计费事实）。
 *
 * 只由 {@see \App\Services\Billing\TenantUsageMeter} 写入，按 `(tenant_id, idempotency_key)` 幂等，
 * AI 调用账本 `ai_model_usage_events` 只作内部成本事实，不参与计费。
 */
class TenantUsageEvent extends Model
{
    public const METER_PUBLISHED_ARTICLES = 'articles_published';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'quantity' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
