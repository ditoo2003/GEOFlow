<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 一次"关键词 × 引擎 × 联网模式 × 周期"的采样批次。
 *
 * 批次是聚合的最小口径单位：联网与非联网样本必须落在不同批次，
 * 因此 `search_enabled` 同时进入唯一键与聚合分组。
 */
class AiVisibilitySampleBatch extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'keyword_hash',
        'engine_key',
        'engine_variant',
        'region',
        'locale',
        'prompt_template_key',
        'prompt_template_version',
        'search_enabled',
        'period',
        'planned_samples',
        'completed_samples',
        'failed_samples',
        'status',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'search_enabled' => 'boolean',
            'period' => 'date',
            'planned_samples' => 'integer',
            'completed_samples' => 'integer',
            'failed_samples' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function samples(): HasMany
    {
        return $this->hasMany(AiVisibilityRun::class, 'sample_batch_id');
    }
}
