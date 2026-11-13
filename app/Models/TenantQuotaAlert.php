<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 租户配额阈值告警（按周期 + 计量项 + 档位幂等）。
 */
class TenantQuotaAlert extends Model
{
    public const METER_PUBLISHED_ARTICLES = 'articles_published';

    public const METER_TOKENS = 'tokens';

    public const METER_VISIBILITY_SAMPLES = 'visibility_samples';

    public const LEVEL_DEGRADE = 'degrade';

    public const LEVEL_BLOCK = 'block';

    public const LEVEL_DEGRADE_UNAVAILABLE = 'degrade_unavailable';

    /** 采样预算已用完：只降频，不中断采集。 */
    public const LEVEL_SATURATED = 'saturated';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'context' => 'array',
            'notified_at' => 'datetime',
        ];
    }
}
