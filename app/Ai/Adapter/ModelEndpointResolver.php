<?php

namespace App\Ai\Adapter;

use App\Models\AiModel;

/**
 * AiModel + 调用形态 → {@see ModelEndpoint}。
 *
 * 五级推导（与设计文档 4.3 一致）：
 * 1. 接入点显式声明的 protocol / capabilities / timeout_profile / thinking_mode
 * 2. provider 预设
 * 3. api_url 尾段特征（/embeddings/multimodal、/responses）
 * 4. model_id 前缀（doubao-embedding-vision- 等）
 * 5. model_type 兜底（chat / embedding）
 *
 * 未填写任何新字段时，输出与历史 `OpenAiRuntimeProvider` 的规则逐条等价。
 */
final class ModelEndpointResolver
{
    public static function resolve(AiModel $model): ModelEndpoint
    {
        $attributes = $model->getAttributes();

        return self::fromParts(
            apiUrl: (string) ($model->api_url ?? ''),
            modelId: (string) ($model->model_id ?? ''),
            modelType: self::normalizeModelType($attributes['model_type'] ?? null),
            providerKey: self::stringOrNull($attributes['provider_key'] ?? null),
            protocol: self::protocolOrNull($attributes['protocol'] ?? null),
            capabilities: self::capabilitiesOrNull($attributes['capabilities'] ?? null),
            contextLength: self::intOrNull($attributes['context_length'] ?? null),
            timeoutProfile: self::timeoutProfileOrNull($attributes['timeout_profile'] ?? null),
            thinkingMode: self::thinkingModeOrNull($attributes['thinking_mode'] ?? null),
        );
    }

    /**
     * @param  list<string>|null  $capabilities
     * @param  array{connect:int,read:int,total:int}|null  $timeoutProfile
     */
    public static function fromParts(
        string $apiUrl,
        string $modelId = '',
        string $modelType = 'chat',
        ?string $providerKey = null,
        ?Protocol $protocol = null,
        ?array $capabilities = null,
        ?int $contextLength = null,
        ?array $timeoutProfile = null,
        ?string $thinkingMode = null,
    ): ModelEndpoint {
        $normalizedUrl = trim($apiUrl);
        $modelType = self::normalizeModelType($modelType);
        $preset = ProviderPreset::forKey($providerKey) ?? ProviderPreset::forUrl($normalizedUrl);
        $resolvedProtocol = $protocol ?? self::deriveProtocol($normalizedUrl, $modelId, $modelType, $preset, $capabilities);
        $resolvedCapabilities = self::deriveCapabilities($capabilities, $preset, $modelType, $resolvedProtocol);

        $baseUrl = $resolvedProtocol->isEmbedding()
            ? self::embeddingBaseUrl($normalizedUrl)
            : self::chatBaseUrl($normalizedUrl);

        return new ModelEndpoint(
            apiUrl: $normalizedUrl,
            modelId: trim($modelId),
            modelType: $modelType,
            providerKey: $preset?->key ?? 'custom',
            protocol: $resolvedProtocol,
            capabilities: $resolvedCapabilities,
            baseUrl: $baseUrl,
            contextLength: $contextLength,
            timeoutProfile: $timeoutProfile,
            thinkingMode: $thinkingMode,
        );
    }

    /**
     * 将历史或自定义 api_url 规范为聊天 provider 可用的 base（根路径时补全 /v1）。
     */
    public static function chatBaseUrl(string $apiUrl): string
    {
        $normalized = trim($apiUrl);
        if ($normalized === '') {
            return '';
        }

        $normalized = rtrim($normalized, '/');
        if (self::isGeminiUrl($normalized)) {
            return self::geminiBaseUrl($normalized);
        }

        $host = strtolower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));
        if ($host === 'api.openai.com' && preg_match('#/responses$#', $normalized) === 1) {
            $normalized = substr($normalized, 0, -strlen('/responses'));
        }

        if (preg_match('#/v1/chat/completions$#', $normalized) === 1) {
            return substr($normalized, 0, -strlen('/chat/completions'));
        }
        if (preg_match('#/chat/completions$#', $normalized) === 1) {
            return substr($normalized, 0, -strlen('/chat/completions'));
        }

        $path = (string) (parse_url($normalized, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            return $normalized.'/v1';
        }

        return $normalized;
    }

    /**
     * 将历史或自定义 api_url 规范为 Embeddings 可用的 base（根路径时补全 /v1）。
     */
    public static function embeddingBaseUrl(string $apiUrl): string
    {
        $normalized = trim($apiUrl);
        if ($normalized === '') {
            return '';
        }

        $normalized = rtrim($normalized, '/');
        if (self::isGeminiUrl($normalized)) {
            return self::geminiBaseUrl($normalized);
        }

        if (self::isVolcengineUrl($normalized)
            && str_ends_with($normalized, self::volcengineMultimodalPath())) {
            return substr($normalized, 0, -strlen(self::volcengineMultimodalPath()));
        }

        if (preg_match('#/v1/embeddings$#', $normalized) === 1) {
            return substr($normalized, 0, -strlen('/embeddings'));
        }
        if (preg_match('#/embeddings$#', $normalized) === 1) {
            return substr($normalized, 0, -strlen('/embeddings'));
        }

        $path = (string) (parse_url($normalized, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            return $normalized.'/v1';
        }

        return $normalized;
    }

    public static function chatDriver(string $apiUrl, string $modelId = ''): string
    {
        $normalized = strtolower(trim($apiUrl));
        $model = strtolower(trim($modelId));
        $host = strtolower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));

        if (self::isGeminiUrl($normalized)) {
            return 'gemini';
        }

        if ($host === 'api.openai.com') {
            return 'openai';
        }

        if (str_contains($host, 'openrouter.ai')) {
            return 'openrouter';
        }

        if (str_contains($host, 'api.deepseek.com') || str_starts_with($model, 'deepseek')) {
            return 'deepseek';
        }

        return 'openai-compatible';
    }

    public static function embeddingDriver(string $apiUrl, string $modelId = ''): string
    {
        if (self::isGeminiUrl($apiUrl)) {
            return 'gemini';
        }

        if (self::isVolcengineMultimodal($apiUrl, $modelId)) {
            return 'volcengine-multimodal';
        }

        $host = strtolower((string) (parse_url(trim($apiUrl), PHP_URL_HOST) ?? ''));

        return $host === 'api.openai.com' ? 'openai' : 'openai-compatible';
    }

    public static function isGeminiUrl(string $apiUrl): bool
    {
        $host = strtolower((string) (parse_url(trim($apiUrl), PHP_URL_HOST) ?? ''));

        return $host === 'generativelanguage.googleapis.com';
    }

    public static function isVolcengineUrl(string $apiUrl): bool
    {
        $host = strtolower((string) (parse_url(trim($apiUrl), PHP_URL_HOST) ?? ''));

        return $host !== '' && str_ends_with($host, '.volces.com');
    }

    public static function isVolcengineMultimodalModel(string $modelId): bool
    {
        return str_starts_with(strtolower(trim($modelId)), 'doubao-embedding-vision-');
    }

    public static function volcengineMultimodalPath(): string
    {
        return '/embeddings/multimodal';
    }

    public static function isVolcengineMultimodal(string $apiUrl, string $modelId): bool
    {
        return self::isVolcengineUrl($apiUrl)
            && (self::isVolcengineMultimodalModel($modelId)
                || str_ends_with(rtrim(trim($apiUrl), '/'), self::volcengineMultimodalPath()));
    }

    public static function geminiBaseUrl(string $apiUrl): string
    {
        $normalized = trim($apiUrl);
        if ($normalized === '') {
            return '';
        }

        $parts = parse_url($normalized);
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return '';
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';

        return strtolower($scheme).'://'.$host.$port.'/v1beta';
    }

    private static function deriveProtocol(
        string $apiUrl,
        string $modelId,
        string $modelType,
        ?ProviderPreset $preset,
        ?array $capabilities = null,
    ): Protocol {
        if (self::isGeminiUrl($apiUrl)) {
            return Protocol::GeminiNative;
        }

        $declared = is_array($capabilities) ? $capabilities : [];
        if (in_array(Capability::MultimodalEmbedding->value, $declared, true)) {
            return Protocol::OpenAiMultimodalEmbedding;
        }
        if (in_array(Capability::Embedding->value, $declared, true)) {
            return Protocol::OpenAiEmbedding;
        }

        if ($modelType === 'embedding') {
            return self::isVolcengineMultimodal($apiUrl, $modelId)
                ? Protocol::OpenAiMultimodalEmbedding
                : Protocol::OpenAiEmbedding;
        }

        $host = strtolower((string) (parse_url($apiUrl, PHP_URL_HOST) ?? ''));
        if ($host === 'api.openai.com' && str_ends_with(rtrim($apiUrl, '/'), '/responses')) {
            return Protocol::OpenAiResponses;
        }

        return $preset?->protocol === Protocol::GeminiNative
            ? Protocol::GeminiNative
            : Protocol::OpenAiChat;
    }

    /**
     * @param  list<string>|null  $capabilities
     * @return list<string>
     */
    private static function deriveCapabilities(
        ?array $capabilities,
        ?ProviderPreset $preset,
        string $modelType,
        Protocol $protocol,
    ): array {
        if (is_array($capabilities) && $capabilities !== []) {
            $resolved = array_values(array_unique(array_filter(
                array_map(static fn (mixed $value): ?string => Capability::tryFromValue($value)?->value, $capabilities),
                static fn (?string $value): bool => $value !== null,
            )));
            if ($resolved !== []) {
                return self::withProtocolCapability($resolved, $protocol);
            }
        }

        if ($preset instanceof ProviderPreset) {
            return self::withProtocolCapability($preset->capabilityValues(), $protocol);
        }

        return self::withProtocolCapability([$modelType === 'embedding' ? Capability::Embedding->value : Capability::Chat->value], $protocol);
    }

    /**
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private static function withProtocolCapability(array $capabilities, Protocol $protocol): array
    {
        $required = match (true) {
            $protocol->isMultimodalEmbedding() => Capability::MultimodalEmbedding,
            $protocol->isEmbedding() => Capability::Embedding,
            default => Capability::Chat,
        };
        if (! in_array($required->value, $capabilities, true)) {
            $capabilities[] = $required->value;
        }

        return array_values(array_unique($capabilities));
    }

    private static function normalizeModelType(mixed $modelType): string
    {
        return is_string($modelType) && strtolower(trim($modelType)) === 'embedding' ? 'embedding' : 'chat';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private static function protocolOrNull(mixed $value): ?Protocol
    {
        return is_string($value) ? Protocol::tryFrom(strtolower(trim($value))) : null;
    }

    /**
     * @return list<string>|null
     */
    private static function capabilitiesOrNull(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        return is_array($value) ? array_values($value) : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @return array{connect:int,read:int,total:int}|null
     */
    private static function timeoutProfileOrNull(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($value)) {
            return null;
        }

        $connect = (int) ($value['connect'] ?? 0);
        $read = (int) ($value['read'] ?? 0);
        $total = (int) ($value['total'] ?? 0);
        if ($connect <= 0 || $read <= 0 || $total <= 0) {
            return null;
        }

        return ['connect' => $connect, 'read' => $read, 'total' => $total];
    }

    private static function thinkingModeOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, ['auto', 'disabled', 'enabled'], true) ? $value : null;
    }
}
