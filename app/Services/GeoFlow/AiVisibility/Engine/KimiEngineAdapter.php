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
 * Kimi（Moonshot Chat Completions + 内置 `$web_search` 工具）。
 */
final class KimiEngineAdapter implements VisibilityEngineAdapter
{
    public function __construct(
        private readonly EngineHttpClient $http,
    ) {}

    public function key(): string
    {
        return AiVisibilityRun::ENGINE_KIMI;
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
            $payload['tools'] = [[
                'type' => 'builtin_function',
                'function' => ['name' => '$web_search'],
            ]];
            $payload['tool_choice'] = 'auto';
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
