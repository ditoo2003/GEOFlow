<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\Concerns\ConfiguresArticleQualityProviderOptions;

/**
 * 质检 issue code 的单一事实源。
 *
 * JSON Schema 枚举（{@see ConfiguresArticleQualityProviderOptions}）
 * 与打分维度映射（{@see ArticleAiQualityScorer}）都从这里派生，避免两份副本各自漂移。
 */
final class ArticleQualityIssueCode
{
    /** 未知 code 归入的最保守维度。 */
    public const UNMAPPED_DIMENSION = 'content_integrity';

    /** 未知 code 的轻扣分上限：只提示注解不达标，不误杀整篇文章。 */
    public const UNMAPPED_LIGHT_DEDUCTION = 2;

    /**
     * 全部合法 code（顺序即 JSON Schema 枚举顺序）。
     */
    public const ALL = [
        'knowledge_contradiction', 'data_mismatch', 'unsupported_claim', 'citation_missing',
        'citation_scope_mismatch', 'ad_absolute_claim', 'ad_false_or_misleading',
        'ad_industry_specific', 'ad_identifiability', 'content_integrity',
        'source_declared_unverified',
    ];

    /**
     * code → 质检维度。
     */
    public const DIMENSIONS = [
        'knowledge_contradiction' => 'knowledge_consistency',
        'unsupported_claim' => 'knowledge_consistency',
        'data_mismatch' => 'data_traceability',
        'citation_missing' => 'data_traceability',
        'citation_scope_mismatch' => 'data_traceability',
        'ad_absolute_claim' => 'advertising_compliance',
        'ad_false_or_misleading' => 'advertising_compliance',
        'ad_industry_specific' => 'advertising_compliance',
        'ad_identifiability' => 'advertising_compliance',
        'content_integrity' => 'content_integrity',
        'source_declared_unverified' => 'knowledge_consistency',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function isKnown(string $code): bool
    {
        return array_key_exists($code, self::DIMENSIONS);
    }

    public static function dimension(string $code): ?string
    {
        return self::DIMENSIONS[$code] ?? null;
    }
}
