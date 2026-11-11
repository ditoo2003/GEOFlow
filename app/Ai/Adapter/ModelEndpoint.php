<?php

namespace App\Ai\Adapter;

/**
 * 一次解析得到的接入点事实（只读值对象）。
 *
 * 同一次调用链内所有调用点共用同一份解析结果，避免"同一模型在质检与生成拿到不同行为"。
 */
final readonly class ModelEndpoint
{
    /**
     * @param  list<string>  $capabilities
     * @param  array{connect:int,read:int,total:int}|null  $timeoutProfile  仅显式声明时非空
     */
    public function __construct(
        public string $apiUrl,
        public string $modelId,
        public string $modelType,
        public string $providerKey,
        public Protocol $protocol,
        public array $capabilities,
        public string $baseUrl,
        public ?int $contextLength = null,
        public ?array $timeoutProfile = null,
        public ?string $thinkingMode = null,
    ) {}

    public function hasCapability(Capability $capability): bool
    {
        return in_array($capability->value, $this->capabilities, true);
    }

    public function isEmbedding(): bool
    {
        return $this->protocol->isEmbedding();
    }

    public function isMultimodalEmbedding(): bool
    {
        return $this->protocol->isMultimodalEmbedding() || $this->hasCapability(Capability::MultimodalEmbedding);
    }

    public function isVolcengine(): bool
    {
        return ModelEndpointResolver::isVolcengineUrl($this->apiUrl);
    }

    public function isGemini(): bool
    {
        return $this->protocol === Protocol::GeminiNative;
    }

    public function tokenField(): string
    {
        return $this->protocol->tokenField();
    }

    public function chatDriver(): string
    {
        return ModelEndpointResolver::chatDriver($this->baseUrl !== '' ? $this->baseUrl : $this->apiUrl, $this->modelId);
    }

    public function embeddingDriver(): string
    {
        return ModelEndpointResolver::embeddingDriver($this->apiUrl, $this->modelId);
    }

    /**
     * 实际请求地址（测试连接与直连调用共用，保证"测试即真实"）。
     */
    public function requestEndpoint(): string
    {
        $base = rtrim($this->baseUrl, '/');

        return $base === '' ? '' : $base.$this->protocol->requestSuffix($this->modelId);
    }

    /**
     * 用于 readiness profile 与账本关联的稳定摘要。
     */
    public function digest(): string
    {
        return hash('sha256', implode('|', [
            $this->providerKey,
            $this->protocol->value,
            rtrim($this->baseUrl, '/'),
            trim($this->modelId),
        ]));
    }

    /**
     * 只做告警的上下文长度估算：中文按 1 字 ≈ 1 token 的保守口径。
     */
    public function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text, 'UTF-8'));
    }
}
