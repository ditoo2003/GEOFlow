<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class AiVisibilityRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const PROVIDER_DOUBAO_ARK_RESPONSES = 'doubao_ark_responses';

    public const PROVIDER_DOUBAO_SEARCH_CUSTOM = 'doubao_search_custom';

    public const PROVIDER_DEEPSEEK_ANALYSIS = 'deepseek_analysis';

    public const PROVIDER_TERM_CLEANUP = 'term_cleanup';

    public const PROVIDER_COMPETITOR_DETECTION = 'competitor_detection';

    /**
     * P0 多引擎采样：一个关键词在一个引擎上的一次样本。
     *
     * 刻意不加入 {@see SAMPLE_PROVIDERS}，避免历史口径的看板/统计把新引擎样本混进来。
     */
    public const PROVIDER_ENGINE_SAMPLE = 'engine_sample';

    public const SAMPLE_PROVIDERS = [self::PROVIDER_DEEPSEEK_ANALYSIS, self::PROVIDER_DOUBAO_ARK_RESPONSES, self::PROVIDER_DOUBAO_SEARCH_CUSTOM];

    public const ENGINE_DOUBAO = 'doubao';

    public const ENGINE_DOUBAO_SEARCH = 'doubao_search';

    public const ENGINE_QWEN = 'qwen';

    public const ENGINE_KIMI = 'kimi';

    public const ENGINE_PERPLEXITY = 'perplexity';

    public const ENGINE_CHATGPT = 'chatgpt';

    public const ENGINE_GEMINI = 'gemini';

    public const ENGINE_DEEPSEEK_ANALYSIS = 'deepseek_analysis';

    public const VARIANT_API = 'api';

    public const VARIANT_BROWSER = 'browser';

    public const METHOD_API = 'api';

    public const METHOD_BROWSER = 'browser';

    public const METHOD_LEGACY = 'legacy';

    protected $fillable = [
        'tenant_id',
        'parent_run_id',
        'uuid',
        'keyword',
        'ai_visibility_topic_id',
        'keyword_hash',
        'prompt',
        'provider_type',
        'provider_key',
        'engine_key',
        'engine_variant',
        'collection_method',
        'region',
        'prompt_template_key',
        'prompt_template_version',
        'sample_batch_id',
        'sample_index',
        'attempt_no',
        'search_enabled',
        'cost_estimate_micros',
        'http_status',
        'is_legacy',
        'ai_model_id',
        'ai_source_provider_id',
        'model_id',
        'status',
        'answer_text',
        'locale',
        'latency_ms',
        'usage_json',
        'analysis_json',
        'raw_request_json',
        'raw_response_json',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'parent_run_id' => 'integer',
            'ai_model_id' => 'integer',
            'ai_visibility_topic_id' => 'integer',
            'ai_source_provider_id' => 'integer',
            'sample_batch_id' => 'integer',
            'sample_index' => 'integer',
            'attempt_no' => 'integer',
            'search_enabled' => 'boolean',
            'cost_estimate_micros' => 'integer',
            'http_status' => 'integer',
            'is_legacy' => 'boolean',
            'latency_ms' => 'integer',
            'usage_json' => 'array',
            'analysis_json' => 'array',
            'raw_request_json' => 'array',
            'raw_response_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'raw_pruned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiVisibilityRun $run): void {
            if (trim((string) $run->uuid) === '') {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(AiSourceProvider::class, 'ai_source_provider_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityTopic::class, 'ai_visibility_topic_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AiVisibilitySource::class, 'ai_visibility_run_id');
    }

    public function competitorDetection(): HasOne
    {
        return $this->hasOne(AiVisibilityCompetitorDetection::class, 'run_id');
    }

    public function sampleBatch(): BelongsTo
    {
        return $this->belongsTo(AiVisibilitySampleBatch::class, 'sample_batch_id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(AiVisibilityMention::class, 'ai_visibility_run_id');
    }
}
