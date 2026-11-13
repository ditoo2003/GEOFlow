<?php

namespace App\Services\GeoFlow\AiVisibility\Engine\Support;

use App\Services\GeoFlow\AiVisibility\Contract\VisibilityCitation;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * 把各引擎形态各异的引用（URL 列表、annotations、search_results）归一成统一引用契约。
 */
final class VisibilityCitationFactory
{
    /**
     * @param  list<mixed>  $urls
     * @return list<VisibilityCitation>
     */
    public static function fromUrlStrings(array $urls, string $kind): array
    {
        $citations = [];
        foreach ($urls as $url) {
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $citations[] = self::citation($kind, trim($url), null, null, null, null);
        }

        return $citations;
    }

    /**
     * @param  list<mixed>  $entries
     * @return list<VisibilityCitation>
     */
    public static function fromEntries(array $entries, string $kind): array
    {
        $citations = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $source = is_array($entry['source'] ?? null) ? $entry['source'] : [];
            $url = self::string($entry['url'] ?? $entry['uri'] ?? ($source['url'] ?? ''));
            $title = self::string($entry['title'] ?? $entry['name'] ?? ($source['title'] ?? ''));
            if ($url === '' && $title === '') {
                continue;
            }

            $citations[] = self::citation(
                kind: $kind,
                url: $url,
                title: $title !== '' ? $title : null,
                snippet: self::string($entry['snippet'] ?? $entry['text'] ?? $entry['summary'] ?? '') ?: null,
                publishedAt: self::date($entry['date'] ?? $entry['last_updated'] ?? $entry['published_at'] ?? null),
                domain: $url !== '' ? self::domain($url) : null,
            );
        }

        return $citations;
    }

    /**
     * 去重（按 URL）并重排引用序号，保证 `citation_index` 连续。
     *
     * @param  list<VisibilityCitation>  $citations
     * @return list<VisibilityCitation>
     */
    public static function merge(array $citations): array
    {
        $seen = [];
        $merged = [];

        foreach ($citations as $citation) {
            $key = strtolower($citation->canonicalUrl ?? $citation->url ?? '');
            if ($key !== '' && isset($seen[$key])) {
                continue;
            }
            if ($key !== '') {
                $seen[$key] = true;
            }

            $index = count($merged) + 1;
            $merged[] = new VisibilityCitation(
                index: $index,
                kind: $citation->kind,
                url: $citation->url,
                canonicalUrl: $citation->canonicalUrl,
                domain: $citation->domain ?? ($citation->url !== null ? self::domain($citation->url) : null),
                title: $citation->title,
                snippet: $citation->snippet,
                publishedAt: $citation->publishedAt,
                rank: $citation->rank ?? $index,
                isOwnedDomain: $citation->isOwnedDomain,
                matchedEntityKey: $citation->matchedEntityKey,
            );
        }

        return $merged;
    }

    public static function domain(string $url): ?string
    {
        $host = strtolower((string) (parse_url(trim($url), PHP_URL_HOST) ?? ''));

        return $host !== '' ? $host : null;
    }

    private static function citation(
        string $kind,
        ?string $url,
        ?string $title,
        ?string $snippet,
        ?CarbonImmutable $publishedAt,
        ?string $domain,
    ): VisibilityCitation {
        return new VisibilityCitation(
            index: 0,
            kind: $kind,
            url: $url,
            domain: $domain ?? ($url !== null && $url !== '' ? self::domain($url) : null),
            title: $title,
            snippet: $snippet,
            publishedAt: $publishedAt,
        );
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            return null;
        }
    }
}
