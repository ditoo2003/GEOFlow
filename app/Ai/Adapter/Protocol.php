<?php

namespace App\Ai\Adapter;

/**
 * 接入点协议：负责"怎么拼请求地址"，与"能不能这么用"（{@see Capability}）分离。
 */
enum Protocol: string
{
    case OpenAiChat = 'openai_chat';
    case OpenAiResponses = 'openai_responses';
    case OpenAiEmbedding = 'openai_embedding';
    case OpenAiMultimodalEmbedding = 'openai_multimodal_embedding';
    case GeminiNative = 'gemini_native';
    case VolcengineResponses = 'volcengine_responses';

    public function isEmbedding(): bool
    {
        return in_array($this, [self::OpenAiEmbedding, self::OpenAiMultimodalEmbedding], true);
    }

    public function isMultimodalEmbedding(): bool
    {
        return $this === self::OpenAiMultimodalEmbedding;
    }

    /**
     * 请求体中输出上限的字段名。
     */
    public function tokenField(): string
    {
        return match ($this) {
            self::OpenAiResponses => 'max_output_tokens',
            self::GeminiNative => 'maxOutputTokens',
            default => 'max_tokens',
        };
    }

    /**
     * 未按 host 细分时的默认 Laravel AI 驱动名。
     */
    public function defaultDriver(): string
    {
        return match ($this) {
            self::GeminiNative => 'gemini',
            default => 'openai-compatible',
        };
    }

    /**
     * 在 base URL 之后追加的请求路径。
     */
    public function requestSuffix(string $modelId = ''): string
    {
        return match ($this) {
            self::OpenAiChat => '/chat/completions',
            self::OpenAiResponses, self::VolcengineResponses => '/responses',
            self::OpenAiEmbedding => '/embeddings',
            self::OpenAiMultimodalEmbedding => '/embeddings/multimodal',
            self::GeminiNative => '/models/'.preg_replace('#^models/#', '', trim($modelId)).':generateContent',
        };
    }
}
