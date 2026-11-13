<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiVisibilityMention;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilityMention;

/**
 * 从回答正文中抽取被提及的客户品牌/竞品实体。
 *
 * P0 只做确定性别名匹配（exact），不调用额外模型：
 * - 匹配用 `mb_stripos`，英文别名大小写不敏感；
 * - `order_index` 按实体首次出现位置排序，供首位率统计；
 * - 情感留给独立分析模型写入，这里不臆测。
 */
final class VisibilityMentionExtractor
{
    /**
     * @param  list<array{entity_type?:string,entity_key:string,canonical_name:string,aliases?:list<string>}>  $entities
     * @return list<VisibilityMention>
     */
    public function extract(string $answerText, array $entities): array
    {
        $answerText = trim($answerText);
        if ($answerText === '' || $entities === []) {
            return [];
        }

        $matched = [];
        foreach ($entities as $entity) {
            $entityKey = trim((string) ($entity['entity_key'] ?? ''));
            $canonicalName = trim((string) ($entity['canonical_name'] ?? ''));
            if ($entityKey === '' || $canonicalName === '') {
                continue;
            }

            $aliases = array_values(array_unique(array_filter(array_map(
                static fn (mixed $alias): string => trim((string) $alias),
                array_merge([$canonicalName], (array) ($entity['aliases'] ?? [])),
            ))));

            $firstOffset = null;
            $firstAlias = null;
            foreach ($aliases as $alias) {
                $offset = mb_stripos($answerText, $alias, 0, 'UTF-8');
                if ($offset === false) {
                    continue;
                }
                if ($firstOffset === null || $offset < $firstOffset) {
                    $firstOffset = $offset;
                    $firstAlias = $alias;
                }
            }

            if ($firstOffset === null || $firstAlias === null) {
                continue;
            }

            $matched[] = [
                'entity' => $entity,
                'entity_key' => $entityKey,
                'canonical_name' => $canonicalName,
                'alias' => $firstAlias,
                'offset' => $firstOffset,
            ];
        }

        usort($matched, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        $mentions = [];
        foreach ($matched as $index => $row) {
            $mentions[] = new VisibilityMention(
                entityType: (string) ($row['entity']['entity_type'] ?? AiVisibilityMention::ENTITY_COMPETITOR),
                entityKey: $row['entity_key'],
                canonicalName: $row['canonical_name'],
                matchedAlias: $row['alias'],
                charOffset: $row['offset'],
                sentenceIndex: $this->sentenceIndex($answerText, $row['offset']),
                orderIndex: $index + 1,
                contextExcerpt: $this->excerpt($answerText, $row['offset']),
            );
        }

        return $mentions;
    }

    private function sentenceIndex(string $text, int $offset): int
    {
        $prefix = mb_substr($text, 0, $offset, 'UTF-8');

        return preg_match_all('/[。！？!?\n]+/u', $prefix) + 1;
    }

    private function excerpt(string $text, int $offset): string
    {
        $start = max(0, $offset - 40);
        $length = mb_strlen($text, 'UTF-8') - $start;

        return mb_substr($text, $start, min(120, max(1, $length)), 'UTF-8');
    }
}
