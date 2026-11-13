<?php

namespace App\Services\GeoFlow\AiVisibility\Contract;

use App\Services\GeoFlow\AiVisibility\AiVisibilitySourceData;
use Carbon\CarbonInterface;

/**
 * 统一引用契约：不同引擎的 inline 角标、脚注、检索结果都归一到这里。
 *
 * `kind` 取值：inline（正文角标）、footnote（脚注/尾注）、search_result（检索结果列表）。
 */
final class VisibilityCitation
{
    public const KIND_INLINE = 'inline';

    public const KIND_FOOTNOTE = 'footnote';

    public const KIND_SEARCH_RESULT = 'search_result';

    public function __construct(
        public readonly int $index,
        public readonly string $kind,
        public readonly ?string $url = null,
        public readonly ?string $canonicalUrl = null,
        public readonly ?string $domain = null,
        public readonly ?string $title = null,
        public readonly ?string $snippet = null,
        public readonly ?CarbonInterface $publishedAt = null,
        public readonly ?int $rank = null,
        public readonly bool $isOwnedDomain = false,
        public readonly ?string $matchedEntityKey = null,
    ) {}

    public function toSourceData(): AiVisibilitySourceData
    {
        return new AiVisibilitySourceData(
            sourceType: $this->kind,
            citationKey: 'c'.$this->index,
            title: $this->title,
            url: $this->canonicalUrl ?? $this->url,
            domain: $this->domain,
            snippet: $this->snippet,
            publishedAt: $this->publishedAt,
            rank: $this->rank ?? $this->index,
            metadata: [
                'citation_kind' => $this->kind,
                'citation_index' => $this->index,
                'is_owned_domain' => $this->isOwnedDomain,
                'matched_entity_key' => $this->matchedEntityKey,
            ],
        );
    }

    /** @return array<string,mixed> */
    public function toSourceAttributes(): array
    {
        return [
            'source_type' => $this->kind,
            'citation_key' => 'c'.$this->index,
            'citation_kind' => $this->kind,
            'citation_index' => $this->index,
            'is_owned_domain' => $this->isOwnedDomain,
            'matched_entity_key' => $this->matchedEntityKey,
            'title' => $this->title,
            'url' => $this->canonicalUrl ?? $this->url,
            'domain' => $this->domain,
            'snippet' => $this->snippet,
            'published_at' => $this->publishedAt?->toDateTimeString(),
            'rank' => $this->rank ?? $this->index,
        ];
    }
}
