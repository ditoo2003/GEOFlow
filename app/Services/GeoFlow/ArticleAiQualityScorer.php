<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Facades\Log;

class ArticleAiQualityScorer
{
    private const DIMENSION_MAXIMUMS = [
        'knowledge_consistency' => 35,
        'data_traceability' => 25,
        'advertising_compliance' => 30,
        'content_integrity' => 10,
    ];

    private const SEVERITY_DEDUCTIONS = [
        'critical' => 20,
        'high' => 12,
        'medium' => 6,
        'low' => 2,
    ];

    /**
     * @param  array<string, mixed>  $modelResult
     * @return array{score:int,dimension_scores:array<string,int>,decision:string,issues:list<array<string,mixed>>,uncertainties:list<array<string,mixed>>}
     */
    public function score(array $modelResult, int $passScore = 85, int $manualOverrideMinScore = 70): array
    {
        if ($manualOverrideMinScore < 0 || $manualOverrideMinScore >= $passScore || $passScore > 100) {
            throw new \InvalidArgumentException('AI quality thresholds are invalid.');
        }

        $issues = $this->uniqueIssues(is_array($modelResult['issues'] ?? null) ? $modelResult['issues'] : []);
        $uncertainties = array_values(is_array($modelResult['uncertainties'] ?? null) ? $modelResult['uncertainties'] : []);
        $dimensionScores = self::DIMENSION_MAXIMUMS;

        $unmappedCodes = [];
        foreach ($issues as &$issue) {
            $code = (string) ($issue['code'] ?? '');
            $severity = (string) ($issue['severity'] ?? 'medium');
            $dimension = ArticleQualityIssueCode::dimension($code) ?? ArticleQualityIssueCode::UNMAPPED_DIMENSION;
            $deduction = self::SEVERITY_DEDUCTIONS[$severity] ?? self::SEVERITY_DEDUCTIONS['medium'];
            if (isset($issue['unmapped_code'])) {
                $unmappedCodes[] = (string) $issue['unmapped_code'];
                // 未知 code 只做轻扣分：既不归零放过，也不按原始 severity 误杀整篇。
                $deduction = min($deduction, ArticleQualityIssueCode::UNMAPPED_LIGHT_DEDUCTION);
            }
            $dimensionScores[$dimension] = max(0, $dimensionScores[$dimension] - $deduction);
            $issue['deduction'] = $deduction;
        }

        unset($issue);

        if ($unmappedCodes !== []) {
            $this->reportUnmappedCodes($unmappedCodes);
        }

        return (new ArticleAiQualityScorePolicy)->finalize(
            $modelResult, $issues, $uncertainties, $dimensionScores, $passScore, $manualOverrideMinScore,
        );
    }

    /**
     * @param  array<int, mixed>  $issues
     * @return list<array<string, mixed>>
     */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $code = (string) ($issue['code'] ?? '');
            if (! ArticleQualityIssueCode::isKnown($code)) {
                // 部分 OpenAI 兼容端点不强制校验 JSON Schema 枚举：
                // 保留原始 code 落库（unmapped_code），并归入最保守维度做轻扣分。
                $issue['reported_code'] = $code;
                $issue['unmapped_code'] = $code;
                $issue['reported_severity'] = (string) ($issue['severity'] ?? 'medium');
                // 未知 code 不允许触发硬拦截：保留模型原始声明，仅用于排障展示。
                $issue['reported_hard_blocker'] = ($issue['hard_blocker'] ?? false) === true;
                $issue['hard_blocker'] = false;
                $issue['severity'] = 'low';
                $issue['code'] = ArticleQualityIssueCode::UNMAPPED_DIMENSION;
            }

            $knowledgeRefs = is_array($issue['knowledge_refs'] ?? null) ? $issue['knowledge_refs'] : [];
            sort($knowledgeRefs);
            $key = hash('sha256', json_encode([
                (string) ($issue['code'] ?? ''),
                (string) ($issue['field'] ?? ''),
                (string) ($issue['quote'] ?? ''),
                $knowledgeRefs,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! isset($unique[$key])) {
                $unique[$key] = $issue;

                continue;
            }
            $hardBlocker = ($unique[$key]['hard_blocker'] ?? false) === true || ($issue['hard_blocker'] ?? false) === true;
            if ((self::SEVERITY_DEDUCTIONS[$issue['severity'] ?? 'medium'] ?? 6)
                > (self::SEVERITY_DEDUCTIONS[$unique[$key]['severity'] ?? 'medium'] ?? 6)) {
                $unique[$key] = $issue;
            }
            $unique[$key]['hard_blocker'] = $hardBlocker;
        }

        return array_values($unique);
    }

    /**
     * @param  list<string>  $codes
     */
    private function reportUnmappedCodes(array $codes): void
    {
        try {
            Log::warning('article_quality_unmapped_issue_code', [
                'codes' => array_values(array_unique($codes)),
                'count' => count($codes),
                'handling' => 'light_deduction',
            ]);
        } catch (\Throwable) {
            // 观测失败不得影响质检打分。
        }
    }
}
