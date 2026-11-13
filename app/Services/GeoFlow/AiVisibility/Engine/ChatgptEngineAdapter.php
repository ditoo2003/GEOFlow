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
 * ChatGPT（OpenAI Responses API + web_search 工具）。
 */
final class ChatgptEngineAdapter implements VisibilityEngineAdapter
{
    public function __construct(
        private readonly EngineHttpClient $http,
    ) {}

    public function key(): string
    {
        return AiVisibilityRun::ENGINE_CHATGPT;
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
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => $request->promptText,
                ]],
            ]],
            'temperature' => $request->temperature,
            'max_output_tokens' => $request->maxOutputTokens,
            'stream' => false,
        ];
        if ($request->searchEnabled) {
            $payload['tools'] = [['type' => 'web_search']];
        }

        $response = $this->http->post($model, '/responses', $payload);
        $answer = EngineResponseParser::responsesAnswer($response->json);
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
            citations: EngineResponseParser::responsesCitations($response->json),
            usage: EngineResponseParser::usage($response->json),
            rawRequest: $response->request,
            rawResponse: $response->json,
            latencyMs: $response->latencyMs,
            httpStatus: $response->status,
            sampledAt: CarbonImmutable::now(),
        );
    }
}
