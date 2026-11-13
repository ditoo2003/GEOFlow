<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiVisibilityMention;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\Engine\VisibilityEngineRegistry;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 多引擎可见性出数服务。
 *
 * 口径（与对外售卖一致）：
 * - 聚合粒度：租户 × 关键词 × 引擎 × 联网模式 × 时间区间（默认近 30 天，可传自然月）；
 * - 只有 `is_legacy = false` 且已完成的样本参与计算，历史豆包/DeepSeek 数据不进新口径；
 * - 联网与非联网样本按 `search_enabled` 分线，永不合并且永不互相补足样本；
 * - 单格有效样本 < 30 时只回样本数，不回比率，避免小样本被当成结论。
 */
final class VisibilityEngineReportService
{
    /** @var array<string,string> */
    public const ENGINE_LABELS = [
        AiVisibilityRun::ENGINE_DOUBAO => '豆包',
        AiVisibilityRun::ENGINE_QWEN => '通义千问',
        AiVisibilityRun::ENGINE_KIMI => 'Kimi',
        AiVisibilityRun::ENGINE_PERPLEXITY => 'Perplexity',
        AiVisibilityRun::ENGINE_CHATGPT => 'ChatGPT',
    ];

    public function __construct(
        private readonly VisibilityEngineRegistry $engines,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{
     *     from?:string|null,
     *     to?:string|null,
     *     engine_keys?:list<string>|null,
     *     keyword_hash?:string|null,
     *     search_enabled?:bool|null,
     *     topic_id?:int|null,
     *     brand_entity_key?:string|null
     * }  $filters
     * @return array<string,mixed>
     */
    public function report(array $filters = []): array
    {
        [$from, $to] = $this->period($filters['from'] ?? null, $filters['to'] ?? null);
        $requiredSamples = max(1, (int) config('geoflow.ai_visibility.min_ready_samples', 30));
        $tenantId = $this->tenants->tenantIdOrDefault();

        $engineKeys = $this->normalizeEngineKeys($filters['engine_keys'] ?? null);
        if ($engineKeys === []) {
            return $this->emptyReport($from, $to, $requiredSamples, $filters);
        }

        $base = fn (): Builder => $this->baseQuery(
            $tenantId,
            $from,
            $to,
            $engineKeys,
            $filters,
        );

        $cells = [];
        foreach ($base()
            ->selectRaw('ai_visibility_runs.engine_key, ai_visibility_runs.keyword_hash, MAX(ai_visibility_runs.keyword) as keyword, ai_visibility_runs.search_enabled, COUNT(*) as valid_samples')
            ->groupBy('ai_visibility_runs.engine_key', 'ai_visibility_runs.keyword_hash', 'ai_visibility_runs.search_enabled')
            ->get() as $row) {
            $key = $this->cellKey((string) $row->engine_key, (string) $row->keyword_hash, (bool) $row->search_enabled);
            $cells[$key] = [
                'engine_key' => (string) $row->engine_key,
                'keyword' => (string) $row->keyword,
                'keyword_hash' => (string) $row->keyword_hash,
                'search_enabled' => (bool) $row->search_enabled,
                'valid_samples' => (int) $row->valid_samples,
                'mentioned_runs' => 0,
                'first_runs' => 0,
                'cited_runs' => 0,
                'top3_owned_runs' => 0,
                'mentions' => [],
                'domains' => [],
            ];
        }

        $mentionStats = $base()
            ->join('ai_visibility_mentions as m', 'm.ai_visibility_run_id', '=', 'ai_visibility_runs.id')
            ->where('m.entity_type', AiVisibilityMention::ENTITY_BRAND)
            ->when(
                isset($filters['brand_entity_key']) && trim((string) $filters['brand_entity_key']) !== '',
                fn (Builder $query): Builder => $query->where('m.entity_key', trim((string) $filters['brand_entity_key'])),
            )
            ->selectRaw('ai_visibility_runs.engine_key, ai_visibility_runs.keyword_hash, ai_visibility_runs.search_enabled, COUNT(DISTINCT ai_visibility_runs.id) as mentioned_runs, COUNT(DISTINCT CASE WHEN m.order_index = 1 THEN ai_visibility_runs.id END) as first_runs')
            ->groupBy('ai_visibility_runs.engine_key', 'ai_visibility_runs.keyword_hash', 'ai_visibility_runs.search_enabled')
            ->get();

        foreach ($mentionStats as $row) {
            $key = $this->cellKey((string) $row->engine_key, (string) $row->keyword_hash, (bool) $row->search_enabled);
            if (! isset($cells[$key])) {
                continue;
            }
            $cells[$key]['mentioned_runs'] = (int) $row->mentioned_runs;
            $cells[$key]['first_runs'] = (int) $row->first_runs;
        }

        $citationStats = $base()
            ->join('ai_visibility_sources as s', 's.ai_visibility_run_id', '=', 'ai_visibility_runs.id')
            ->selectRaw('ai_visibility_runs.engine_key, ai_visibility_runs.keyword_hash, ai_visibility_runs.search_enabled, COUNT(DISTINCT ai_visibility_runs.id) as cited_runs, COUNT(DISTINCT CASE WHEN s.is_owned_domain = true AND s.citation_index <= 3 THEN ai_visibility_runs.id END) as top3_owned_runs')
            ->groupBy('ai_visibility_runs.engine_key', 'ai_visibility_runs.keyword_hash', 'ai_visibility_runs.search_enabled')
            ->get();

        foreach ($citationStats as $row) {
            $key = $this->cellKey((string) $row->engine_key, (string) $row->keyword_hash, (bool) $row->search_enabled);
            if (! isset($cells[$key])) {
                continue;
            }
            $cells[$key]['cited_runs'] = (int) $row->cited_runs;
            $cells[$key]['top3_owned_runs'] = (int) $row->top3_owned_runs;
        }

        $sovStats = $base()
            ->join('ai_visibility_mentions as m', 'm.ai_visibility_run_id', '=', 'ai_visibility_runs.id')
            ->selectRaw('ai_visibility_runs.engine_key, ai_visibility_runs.keyword_hash, ai_visibility_runs.search_enabled, m.entity_key, MAX(m.canonical_name) as canonical_name, COUNT(*) as mention_count')
            ->groupBy('ai_visibility_runs.engine_key', 'ai_visibility_runs.keyword_hash', 'ai_visibility_runs.search_enabled', 'm.entity_key')
            ->get();

        $sovTotals = [];
        foreach ($sovStats as $row) {
            $key = $this->cellKey((string) $row->engine_key, (string) $row->keyword_hash, (bool) $row->search_enabled);
            if (! isset($cells[$key])) {
                continue;
            }
            $cells[$key]['mentions'][] = [
                'entity_key' => (string) $row->entity_key,
                'canonical_name' => (string) $row->canonical_name,
                'mention_count' => (int) $row->mention_count,
            ];
            $sovTotals[$key] = ($sovTotals[$key] ?? 0) + (int) $row->mention_count;
        }

        $domainStats = $base()
            ->join('ai_visibility_sources as s', 's.ai_visibility_run_id', '=', 'ai_visibility_runs.id')
            ->whereNotNull('s.domain')
            ->selectRaw('ai_visibility_runs.engine_key, ai_visibility_runs.keyword_hash, ai_visibility_runs.search_enabled, s.domain, COUNT(*) as citations')
            ->groupBy('ai_visibility_runs.engine_key', 'ai_visibility_runs.keyword_hash', 'ai_visibility_runs.search_enabled', 's.domain')
            ->orderByDesc('citations')
            ->get();

        foreach ($domainStats as $row) {
            $key = $this->cellKey((string) $row->engine_key, (string) $row->keyword_hash, (bool) $row->search_enabled);
            if (! isset($cells[$key]) || count($cells[$key]['domains']) >= 5) {
                continue;
            }
            $cells[$key]['domains'][] = [
                'domain' => (string) $row->domain,
                'citations' => (int) $row->citations,
            ];
        }

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'required_samples' => $requiredSamples,
            'search_enabled' => $filters['search_enabled'] ?? null,
            'engines' => $this->assembleEngines($cells, $sovTotals, $requiredSamples),
        ];
    }

    /**
     * 按引擎分组输出；每个格子带就绪状态，未达样本门槛的格子不给比率。
     *
     * @param  array<string,array<string,mixed>>  $cells
     * @param  array<string,int>  $sovTotals
     * @return list<array<string,mixed>>
     */
    private function assembleEngines(array $cells, array $sovTotals, int $requiredSamples): array
    {
        $grouped = [];
        foreach ($cells as $key => $cell) {
            $valid = (int) $cell['valid_samples'];
            $ready = $valid >= $requiredSamples;
            $totalMentions = max(1, (int) ($sovTotals[$key] ?? 0));

            $sov = array_map(
                static fn (array $mention): array => [
                    'entity_key' => $mention['entity_key'],
                    'canonical_name' => $mention['canonical_name'],
                    'mention_count' => $mention['mention_count'],
                    'share' => round($mention['mention_count'] / $totalMentions, 4),
                ],
                $cell['mentions'],
            );
            usort($sov, static fn (array $left, array $right): int => $right['mention_count'] <=> $left['mention_count']);

            $reportCell = [
                'keyword' => $cell['keyword'],
                'keyword_hash' => $cell['keyword_hash'],
                'search_enabled' => $cell['search_enabled'],
                'valid_samples' => $valid,
                'required_samples' => $requiredSamples,
                'ready' => $ready,
                'status' => $ready ? 'ready' : 'insufficient_samples',
                'mention_rate' => null,
                'mention_rate_ci' => null,
                'first_position_rate' => null,
                'citation_coverage_rate' => null,
                'top3_owned_citation_rate' => null,
                'sov' => $ready ? $sov : [],
                'top_domains' => $ready ? $cell['domains'] : [],
            ];

            if ($ready) {
                $reportCell['mention_rate'] = round($cell['mentioned_runs'] / $valid, 4);
                $reportCell['mention_rate_ci'] = $this->wilsonInterval((int) $cell['mentioned_runs'], $valid);
                $reportCell['first_position_rate'] = round($cell['first_runs'] / $valid, 4);
                $reportCell['citation_coverage_rate'] = round($cell['cited_runs'] / $valid, 4);
                $reportCell['top3_owned_citation_rate'] = round($cell['top3_owned_runs'] / $valid, 4);
            }

            $grouped[(string) $cell['engine_key']][] = $reportCell;
        }

        $engines = [];
        foreach ($this->engines->keys() as $engineKey) {
            $engineCells = $grouped[$engineKey] ?? [];
            usort($engineCells, static function (array $left, array $right): int {
                return [$left['keyword'], $left['search_enabled'] ? 0 : 1] <=> [$right['keyword'], $right['search_enabled'] ? 0 : 1];
            });
            $readyCells = count(array_filter($engineCells, static fn (array $cell): bool => $cell['ready']));

            $engines[] = [
                'engine_key' => $engineKey,
                'label' => self::ENGINE_LABELS[$engineKey] ?? $engineKey,
                'cells' => $engineCells,
                'total_cells' => count($engineCells),
                'ready_cells' => $readyCells,
            ];
        }

        return $engines;
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function emptyReport(CarbonImmutable $from, CarbonImmutable $to, int $requiredSamples, array $filters): array
    {
        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'required_samples' => $requiredSamples,
            'search_enabled' => $filters['search_enabled'] ?? null,
            'engines' => [],
        ];
    }

    /**
     * @param  list<string>  $engineKeys
     * @param  array<string,mixed>  $filters
     */
    private function baseQuery(
        int $tenantId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $engineKeys,
        array $filters,
    ): Builder {
        return DB::table('ai_visibility_runs')
            ->where('ai_visibility_runs.tenant_id', $tenantId)
            ->where('ai_visibility_runs.is_legacy', false)
            ->where('ai_visibility_runs.status', AiVisibilityRun::STATUS_COMPLETED)
            ->whereNotNull('ai_visibility_runs.completed_at')
            ->whereBetween('ai_visibility_runs.completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereIn('ai_visibility_runs.engine_key', $engineKeys)
            ->when(
                isset($filters['keyword_hash']) && trim((string) $filters['keyword_hash']) !== '',
                fn (Builder $query): Builder => $query->where('ai_visibility_runs.keyword_hash', trim((string) $filters['keyword_hash'])),
            )
            ->when(
                array_key_exists('search_enabled', $filters) && $filters['search_enabled'] !== null,
                fn (Builder $query): Builder => $query->where('ai_visibility_runs.search_enabled', (bool) $filters['search_enabled']),
            )
            ->when(
                isset($filters['topic_id']) && (int) $filters['topic_id'] > 0,
                fn (Builder $query): Builder => $query->where('ai_visibility_runs.ai_visibility_topic_id', (int) $filters['topic_id']),
            );
    }

    /**
     * @param  list<string>|null  $engineKeys
     * @return list<string>
     */
    private function normalizeEngineKeys(?array $engineKeys): array
    {
        $registered = $this->engines->keys();
        if ($engineKeys === null || $engineKeys === []) {
            return $registered;
        }

        $filtered = array_values(array_intersect(array_map('strval', $engineKeys), $registered));

        return $filtered;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function period(?string $from, ?string $to): array
    {
        $end = $this->parseDate($to) ?? CarbonImmutable::today();
        $start = $this->parseDate($from) ?? $end->subDays(29);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start->startOfDay(), $end->startOfDay()];
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }

    private function cellKey(string $engineKey, string $keywordHash, bool $searchEnabled): string
    {
        return $engineKey.'|'.$keywordHash.'|'.($searchEnabled ? '1' : '0');
    }

    /**
     * Wilson 95% 置信区间：小样本比率不至于被读成确定结论。
     *
     * @return array{0:float,1:float}
     */
    private function wilsonInterval(int $successes, int $samples, float $z = 1.96): array
    {
        if ($samples <= 0) {
            return [0.0, 0.0];
        }

        $p = $successes / $samples;
        $denominator = 1 + ($z * $z) / $samples;
        $centre = $p + ($z * $z) / (2 * $samples);
        $margin = $z * sqrt(($p * (1 - $p)) / $samples + ($z * $z) / (4 * $samples * $samples));

        return [
            round(max(0.0, ($centre - $margin) / $denominator), 4),
            round(min(1.0, ($centre + $margin) / $denominator), 4),
        ];
    }
}
