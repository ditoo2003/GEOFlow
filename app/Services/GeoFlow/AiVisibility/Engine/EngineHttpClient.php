<?php

namespace App\Services\GeoFlow\AiVisibility\Engine;

use App\Models\AiModel;
use App\Services\GeoFlow\AiVisibility\AiVisibilityHttpClientFactory;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

/**
 * 多引擎统一出站客户端。
 *
 * - 采样是计费请求，默认单次尝试（重试由上层按 `attempt_no` 显式发起），避免盲目重试放大成本；
 * - 错误信息不携带响应正文，只保留 HTTP 状态，防止把供应商错误页写进日志。
 */
final class EngineHttpClient
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiVisibilityHttpClientFactory $httpClientFactory,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function post(AiModel $model, string $path, array $payload): EngineHttpResponse
    {
        $endpoint = self::endpoint($model, $path);
        $apiKey = trim($this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? '')));
        if ($apiKey === '') {
            throw new RuntimeException('ai_provider_api_key_missing');
        }

        $startedAt = hrtime(true);
        $response = $this->httpClientFactory
            ->singleAttemptJsonRequest($apiKey)
            ->post($endpoint, $payload);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('visibility engine request failed: HTTP %d', $response->status()));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('ai_provider_response_invalid');
        }

        return new EngineHttpResponse(
            status: $response->status(),
            json: $json,
            request: ['endpoint' => $endpoint, 'payload' => $payload],
            latencyMs: $latencyMs,
        );
    }

    /**
     * `api_url` 按"接入点 base"使用：已带版本前缀（如 /v1、/compatible-mode/v1）直接拼路径；
     * 根域名同样只拼路径本身，不擅自补 /v1，避免把 Perplexity 根域名拼错。
     */
    public static function endpoint(AiModel $model, string $path): string
    {
        $apiUrl = rtrim(trim((string) ($model->api_url ?? '')), '/');
        if ($apiUrl === '') {
            throw new RuntimeException('ai_provider_endpoint_missing');
        }

        $path = '/'.ltrim($path, '/');

        return str_ends_with(strtolower($apiUrl), strtolower($path)) ? $apiUrl : $apiUrl.$path;
    }
}
