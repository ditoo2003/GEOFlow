<?php

namespace App\Services\GeoFlow\AiVisibility\Contract;

use Carbon\CarbonInterface;

/**
 * 适配器输出的统一样本契约。
 *
 * - `engineKey` 是稳定身份（doubao/qwen/kimi/perplexity/chatgpt），`modelKey` 记录当期模型版本；
 * - `citations` 为空是合法结果（`citation_count = 0` 记为 no_citation，不是失败）；
 * - `searchEnabled` 必须与请求一致，落库后联网/非联网样本分线聚合。
 */
final class VisibilitySample
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * @param  list<VisibilityCitation>  $citations
     * @param  list<VisibilityMention>  $mentions
     * @param  array<string,mixed>  $usage
     * @param  array<string,mixed>  $rawRequest
     * @param  array<string,mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $engineKey,
        public readonly string $engineVariant,
        public readonly string $collectionMethod,
        public readonly ?string $modelKey,
        public readonly VisibilitySampleRequest $request,
        public readonly string $status,
        public readonly string $answerText = '',
        public readonly array $citations = [],
        public readonly array $mentions = [],
        public readonly array $usage = [],
        public readonly array $rawRequest = [],
        public readonly array $rawResponse = [],
        public readonly int $latencyMs = 0,
        public readonly ?int $httpStatus = null,
        public readonly ?int $costEstimateMicros = null,
        public readonly ?string $errorCode = null,
        public readonly ?CarbonInterface $sampledAt = null,
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function hasCitations(): bool
    {
        return $this->citations !== [];
    }

    /**
     * 写入 `ai_visibility_runs` 的引擎口径字段（不含样本内容本身）。
     *
     * @return array<string,mixed>
     */
    public function toRunAttributes(): array
    {
        return [
            'engine_key' => $this->engineKey,
            'engine_variant' => $this->engineVariant,
            'collection_method' => $this->collectionMethod,
            'region' => $this->request->region,
            'locale' => $this->request->locale,
            'prompt_template_key' => $this->request->promptTemplateKey,
            'prompt_template_version' => $this->request->promptTemplateVersion(),
            'sample_index' => $this->request->sampleIndex,
            'attempt_no' => $this->request->attemptNo,
            'search_enabled' => $this->request->searchEnabled,
            'cost_estimate_micros' => $this->costEstimateMicros,
            'http_status' => $this->httpStatus,
            'is_legacy' => false,
        ];
    }
}
