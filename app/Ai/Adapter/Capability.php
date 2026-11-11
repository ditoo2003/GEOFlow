<?php

namespace App\Ai\Adapter;

/**
 * 模型接入点的能力词汇表。
 *
 * `chat` / `embedding` / `multimodal_embedding` 是可执行能力，决定接入点可以出现在哪些调用场景；
 * 其余为协议特性声明，供 providerOptions 构建与一致性校验使用。
 */
enum Capability: string
{
    case Chat = 'chat';
    case Embedding = 'embedding';
    case MultimodalEmbedding = 'multimodal_embedding';
    case Thinking = 'thinking';
    case JsonObject = 'json_object';
    case JsonSchema = 'json_schema';
    case Tools = 'tools';
    case Vision = 'vision';
    case Streaming = 'streaming';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $capability): string => $capability->value, self::cases());
    }

    /** @return list<string> */
    public static function executableValues(): array
    {
        return [self::Chat->value, self::Embedding->value, self::MultimodalEmbedding->value];
    }

    public static function isExecutable(string $value): bool
    {
        return in_array($value, self::executableValues(), true);
    }

    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom(strtolower(trim($value))) : null;
    }
}
