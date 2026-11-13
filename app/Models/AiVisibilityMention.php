<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 单条样本中被识别出的品牌/竞品提及。
 *
 * `entity_type`：brand（客户品牌）或 competitor（被跟踪竞品）；
 * `order_index` 表示该实体在回答中第几个被提到，用于首位率统计。
 */
class AiVisibilityMention extends Model
{
    public const ENTITY_BRAND = 'brand';

    public const ENTITY_COMPETITOR = 'competitor';

    protected $fillable = [
        'tenant_id',
        'ai_visibility_run_id',
        'entity_type',
        'entity_key',
        'canonical_name',
        'matched_alias',
        'char_offset',
        'sentence_index',
        'order_index',
        'context_excerpt',
        'confidence',
        'verification_method',
        'sentiment',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'ai_visibility_run_id' => 'integer',
            'char_offset' => 'integer',
            'sentence_index' => 'integer',
            'order_index' => 'integer',
            'confidence' => 'float',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityRun::class, 'ai_visibility_run_id');
    }
}
