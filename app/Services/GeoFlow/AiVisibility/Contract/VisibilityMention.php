<?php

namespace App\Services\GeoFlow\AiVisibility\Contract;

/**
 * 统一提及契约：回答中被识别出的品牌/竞品实体。
 *
 * `orderIndex` 是实体在回答中的提及顺序（从 1 开始），首位率以它为准；
 * 情感不来自引擎，`sentiment` 只由独立分析模型写入并标注验证方式。
 */
final class VisibilityMention
{
    public function __construct(
        public readonly string $entityType,
        public readonly string $entityKey,
        public readonly string $canonicalName,
        public readonly ?string $matchedAlias = null,
        public readonly ?int $charOffset = null,
        public readonly ?int $sentenceIndex = null,
        public readonly ?int $orderIndex = null,
        public readonly ?string $contextExcerpt = null,
        public readonly float $confidence = 1.0,
        public readonly string $verificationMethod = 'exact',
        public readonly ?string $sentiment = null,
    ) {}

    /** @return array<string,mixed> */
    public function toDatabaseAttributes(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_key' => $this->entityKey,
            'canonical_name' => $this->canonicalName,
            'matched_alias' => $this->matchedAlias,
            'char_offset' => $this->charOffset,
            'sentence_index' => $this->sentenceIndex,
            'order_index' => $this->orderIndex,
            'context_excerpt' => $this->contextExcerpt,
            'confidence' => $this->confidence,
            'verification_method' => $this->verificationMethod,
            'sentiment' => $this->sentiment,
        ];
    }
}
