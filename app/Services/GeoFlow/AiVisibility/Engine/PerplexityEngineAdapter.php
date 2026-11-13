<?php

namespace App\Services\GeoFlow\AiVisibility\Engine;

use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySample;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySampleRequest;
use App\Services\GeoFlow\AiVisibility\Engine\Support\EngineResponseParser;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Perplexity（Sonar）。
 *
 * Sonar 系列始终联网检索，因此不接受 `search_enabled = false`：
 * 宁可报错，也不把联网样本标成非联网。
 */
final class PerplexityEngineAdapter implements VisibilityEngineAdapter
{
    public function __construct(
        private readonly EngineHttpClient $http,
    ) {}

    public function key(): string
    {
        return AiVisibilityRun::ENGINE_PERPLEXITY;
    }

    public function variant(): string
    {
        return AiVisibilityRun::VARIANT_API;
    }

    public function supportsSearchToggle(): bool
    {
        return false;
    }

    public function supportsCitations(): bool
    {
        return true;
    }

    public function sample(AiModel $model, VisibilitySampleRequest $request): VisibilitySample
    {
        if (! $request->searchEnabled) {
            throw new RuntimeException('ai_visibility_search_toggle_unsupported');
        }

        $modelId = trim((string) ($model->model_id ?? ''));
        if ($modelId === '') {
            throw new RuntimeException('ai_provider_model_missing');
        }

        $response = $this->http->post($model, '/chat/completions', [
            'model' => $modelId,
            'messages' => [
                ['role' => 'user', 'content' => $request->promptText],
            ],
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxOutputTokens,
            'stream' => false,
        ]);

        $answer = EngineResponseParser::chatAnswer($response->json);
        if ($answer === '') {
            throw new RuntimeException('ai_provider_empty_answer');
        }

        return new VisibilitySample(
            engineKey: $this->key(),
            engineVariant: $this->variant(),
            collectionMethod: AiVisibilityRun::METHOD_API,
            modelKey: $modelId,
            request: $request,
            status: VisibilitySample::STATUS_COMPLETED,
            answerText: $answer,
            citations: EngineResponseParser::chatCitations($response->json),
            usage: EngineResponseParser::usage($response->json),
            rawRequest: $response->request,
            rawResponse: $response->json,
            latencyMs: $response->latencyMs,
            httpStatus: $response->status,
            sampledAt: CarbonImmutable::now(),
        );
    }
}
