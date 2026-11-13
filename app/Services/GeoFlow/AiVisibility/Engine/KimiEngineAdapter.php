<?php

namespace App\Services\GeoFlow\AiVisibility\Engine;

use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySample;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySampleRequest;
use App\Services\GeoFlow\AiVisibility\Engine\Support\EngineResponseParser;
use App\Services\GeoFlow\AiVisibility\Engine\Support\VisibilityCitationFactory;
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

        // Kimi K3 系列只接受 temperature=1（传 0 直接 400），因此不传该参数，使用服务端默认采样温度。
        // K3 会先消耗推理 token，预算太小会把正文挤成空串；用 max_completion_tokens 并给足下限。
        $payload = [
            'model' => $modelId,
            'messages' => [
                ['role' => 'user', 'content' => $request->promptText],
            ],
            'max_completion_tokens' => $this->tokenBudget($request->maxOutputTokens),
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
        $json = $response->json;
        $answer = EngineResponseParser::chatAnswer($json);
        $citations = EngineResponseParser::chatCitations($json);
        $usage = EngineResponseParser::usage($json);
        $rawRounds = [$json];
        $latencyMs = $response->latencyMs;

        // 内置 `$web_search` 不是服务端自动完成：首轮只回 tool_calls，
        // 必须把 arguments 原样回填成 role=tool 再请求一次，搜索结果才会注入上下文。
        $toolCalls = $this->searchToolCalls($json);
        if ($request->searchEnabled && $toolCalls !== []) {
            $messages = $payload['messages'];
            $messages[] = is_array($json['choices'][0]['message'] ?? null) ? $json['choices'][0]['message'] : [];
            foreach ($toolCalls as $call) {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($call['id'] ?? ''),
                    'name' => (string) ($call['function']['name'] ?? ''),
                    'content' => (string) ($call['function']['arguments'] ?? ''),
                ];
            }

            $response = $this->http->post($model, '/chat/completions', [
                'model' => $modelId,
                'messages' => $messages,
                'max_completion_tokens' => $this->tokenBudget($request->maxOutputTokens),
                'stream' => false,
                'tools' => $payload['tools'],
                'tool_choice' => 'auto',
            ]);
            $json = $response->json;
            $rawRounds[] = $json;
            $answer = EngineResponseParser::chatAnswer($json) !== ''
                ? EngineResponseParser::chatAnswer($json)
                : $answer;
            $citations = VisibilityCitationFactory::merge(array_merge(
                $citations,
                EngineResponseParser::chatCitations($json),
            ));
            $usage = $this->mergeUsage($usage, EngineResponseParser::usage($json));
            $latencyMs += $response->latencyMs;
        }

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
            citations: $citations,
            usage: $usage,
            rawRequest: $response->request,
            rawResponse: count($rawRounds) === 1 ? $rawRounds[0] : ['rounds' => $rawRounds],
            latencyMs: $latencyMs,
            httpStatus: $response->status,
            sampledAt: CarbonImmutable::now(),
        );
    }

    /**
     * K3 的推理 token 与正文共享预算，实测 1000 经常在推理阶段耗尽导致正文为空。
     */
    private function tokenBudget(int $requested): int
    {
        return max(2048, $requested);
    }

    /**
     * @param  array<string,mixed>  $json
     * @return list<array<string,mixed>>
     */
    private function searchToolCalls(array $json): array
    {
        $message = is_array($json['choices'][0]['message'] ?? null) ? $json['choices'][0]['message'] : [];
        $calls = [];

        foreach (is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [] as $call) {
            if (is_array($call) && ($call['function']['name'] ?? '') === '$web_search') {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /**
     * 两轮请求都计入用量，按字段求和。
     *
     * @param  array<string,mixed>  $first
     * @param  array<string,mixed>  $second
     * @return array<string,mixed>
     */
    private function mergeUsage(array $first, array $second): array
    {
        if ($first === [] || $second === []) {
            return $second !== [] ? $second : $first;
        }

        $merged = $second;
        foreach ($first as $key => $value) {
            if (is_int($value) && is_int($merged[$key] ?? null)) {
                $merged[$key] = $value + $merged[$key];
            } elseif (! array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
