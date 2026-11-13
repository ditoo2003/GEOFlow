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
 * 通义千问（DashScope OpenAI 兼容模式 + enable_search）。
 *
 * 关闭联网时不传 `enable_search`，模型仅凭参数知识回答。
 */
final class QwenEngineAdapter implements VisibilityEngineAdapter
{
    public function __construct(
        private readonly EngineHttpClient $http,
    ) {}

    public function key(): string
    {
        return AiVisibilityRun::ENGINE_QWEN;
    }

    public function variant(): string
    {
        return AiVisibilityRun::VARIANT_API;
    }

    public function supportsSearchToggle(): bool
    {
        return true;
    }

    public function supportsCitations(): bool
    {
        return true;
    }

    public function sample(AiModel $model, VisibilitySampleRequest $request): VisibilitySample
    {
        $modelId = trim((string) ($model->model_id ?? ''));
        if ($modelId === '') {
            throw new RuntimeException('ai_provider_model_missing');
        }

        $payload = [
            'model' => $modelId,
            'messages' => [
                ['role' => 'user', 'content' => $request->promptText],
            ],
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxOutputTokens,
            'stream' => false,
        ];
        if ($request->searchEnabled) {
            $payload['enable_search'] = true;
            $payload['search_options'] = [
                'forced_search' => true,
                'enable_source' => true,
                'enable_citation' => true,
            ];
        }

        $response = $this->http->post($model, '/chat/completions', $payload);
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
