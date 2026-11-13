<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\AiVisibilityKeywordNormalizer;
use App\Services\GeoFlow\AiVisibility\VisibilityEngineReportService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisibilityEngineReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private const KEYWORD = 'AI 搜索推荐';

    public function test_cells_below_the_sample_threshold_expose_counts_only(): void
    {
        $this->seedRuns(29, brandMentions: 20, firstPositions: 10);

        $cell = $this->cell($this->report(), 'qwen', true);

        $this->assertNotNull($cell);
        $this->assertSame(29, $cell['valid_samples']);
        $this->assertSame(30, $cell['required_samples']);
        $this->assertFalse($cell['ready']);
        $this->assertSame('insufficient_samples', $cell['status']);
        $this->assertNull($cell['mention_rate']);
        $this->assertNull($cell['mention_rate_ci']);
        $this->assertNull($cell['first_position_rate']);
        $this->assertSame([], $cell['sov']);

        $this->seedRuns(1, brandMentions: 1, firstPositions: 1);
        $cell = $this->cell($this->report(), 'qwen', true);

        $this->assertTrue($cell['ready']);
        $this->assertSame(0.7, $cell['mention_rate']);
        $this->assertSame(0.3667, $cell['first_position_rate']);
        $this->assertCount(2, $cell['mention_rate_ci']);
    }

    public function test_online_and_offline_samples_are_never_merged_into_one_cell(): void
    {
        $this->seedRuns(30, searchEnabled: true, brandMentions: 30, firstPositions: 30);
        $this->seedRuns(30, searchEnabled: false, brandMentions: 0, firstPositions: 0);

        $report = $this->report();
        $online = $this->cell($report, 'qwen', true);
        $offline = $this->cell($report, 'qwen', false);

        $this->assertSame(30, $online['valid_samples']);
        $this->assertSame(1.0, $online['mention_rate']);
        $this->assertSame(30, $offline['valid_samples']);
        $this->assertSame(0.0, $offline['mention_rate']);

        $filtered = $this->report(['search_enabled' => false]);
        $this->assertCount(1, $filtered['engines'][1]['cells']);
        $this->assertFalse($filtered['engines'][1]['cells'][0]['search_enabled']);
    }

    public function test_legacy_and_unfinished_runs_are_excluded_from_the_new_scope(): void
    {
        $this->seedRuns(30, brandMentions: 10, firstPositions: 5);
        $this->seedRuns(20, legacy: true, brandMentions: 20, firstPositions: 20);
        $this->seedRuns(10, status: AiVisibilityRun::STATUS_FAILED, brandMentions: 10, firstPositions: 10);

        $cell = $this->cell($this->report(), 'qwen', true);

        $this->assertSame(30, $cell['valid_samples']);
        $this->assertSame(round(10 / 30, 4), $cell['mention_rate']);
    }

    public function test_share_of_voice_splits_brand_and_competitors(): void
    {
        $this->seedRuns(30, brandMentions: 30, firstPositions: 20);
        $this->seedCompetitorMentions(10, 'rivalai', 'RivalAI');

        $cell = $this->cell($this->report(), 'qwen', true);

        $shareByEntity = collect($cell['sov'])->pluck('share', 'entity_key')->all();
        $this->assertSame(0.75, $shareByEntity['geoflow']);
        $this->assertSame(0.25, $shareByEntity['rivalai']);
    }

    public function test_citation_coverage_and_owned_top3_rate_are_reported(): void
    {
        $runIds = $this->seedRuns(30, brandMentions: 0, firstPositions: 0);
        foreach (array_slice($runIds, 0, 15) as $index => $runId) {
            $this->seedSource($runId, 'example.com', citationIndex: 1, owned: $index < 5);
        }

        $cell = $this->cell($this->report(), 'qwen', true);

        $this->assertSame(0.5, $cell['citation_coverage_rate']);
        $this->assertSame(round(5 / 30, 4), $cell['top3_owned_citation_rate']);
        $this->assertSame('example.com', $cell['top_domains'][0]['domain']);
    }

    public function test_report_endpoint_requires_super_admin_and_returns_json(): void
    {
        $this->seedRuns(30, brandMentions: 30, firstPositions: 30);

        $ordinary = $this->admin('admin');
        $this->actingAs($ordinary, 'admin')
            ->getJson(route('admin.analytics.ai-visibility.engines.report'))
            ->assertForbidden();

        $super = $this->admin('super_admin');
        $this->actingAs($super, 'admin')
            ->getJson(route('admin.analytics.ai-visibility.engines.report'))
            ->assertOk()
            ->assertJsonPath('required_samples', 30)
            ->assertJsonPath('engines.0.engine_key', AiVisibilityRun::ENGINE_DOUBAO)
            ->assertJsonPath('engines.1.engine_key', AiVisibilityRun::ENGINE_QWEN);

        $this->actingAs($super, 'admin')
            ->get(route('admin.analytics.ai-visibility.engines'))
            ->assertOk()
            ->assertSee('多引擎 AI 可见性');
    }

    /** @param array<string,mixed> $filters */
    private function report(array $filters = []): array
    {
        return app(VisibilityEngineReportService::class)->report($filters);
    }

    /** @param array<string,mixed> $report */
    private function cell(array $report, string $engineKey, bool $searchEnabled): ?array
    {
        $engine = collect($report['engines'])->firstWhere('engine_key', $engineKey);
        $this->assertNotNull($engine, '未找到引擎 '.$engineKey);

        return collect($engine['cells'])->firstWhere('search_enabled', $searchEnabled);
    }

    /**
     * @return list<int>
     */
    private function seedRuns(
        int $count,
        bool $searchEnabled = true,
        bool $legacy = false,
        string $status = AiVisibilityRun::STATUS_COMPLETED,
        int $brandMentions = 0,
        int $firstPositions = 0,
        string $engineKey = AiVisibilityRun::ENGINE_QWEN,
    ): array {
        $tenantId = app(TenantContext::class)->tenantIdOrDefault();
        $keywordHash = AiVisibilityKeywordNormalizer::hash(self::KEYWORD);
        $runIds = [];

        for ($index = 0; $index < $count; $index++) {
            $runId = (int) DB::table('ai_visibility_runs')->insertGetId([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $tenantId,
                'keyword' => self::KEYWORD,
                'keyword_hash' => $keywordHash,
                'prompt' => self::KEYWORD,
                'provider_type' => AiVisibilityRun::PROVIDER_ENGINE_SAMPLE,
                'provider_key' => $engineKey,
                'engine_key' => $engineKey,
                'engine_variant' => AiVisibilityRun::VARIANT_API,
                'collection_method' => AiVisibilityRun::METHOD_API,
                'region' => 'global',
                'locale' => 'zh_CN',
                'search_enabled' => $searchEnabled,
                'is_legacy' => $legacy,
                'status' => $status,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $runIds[] = $runId;

            if ($index < $brandMentions) {
                DB::table('ai_visibility_mentions')->insert([
                    'tenant_id' => $tenantId,
                    'ai_visibility_run_id' => $runId,
                    'entity_type' => 'brand',
                    'entity_key' => 'geoflow',
                    'canonical_name' => 'GEOFlow',
                    'order_index' => $index < $firstPositions ? 1 : 2,
                    'confidence' => 1.0,
                    'verification_method' => 'exact',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $runIds;
    }

    private function seedCompetitorMentions(int $count, string $entityKey, string $canonicalName): void
    {
        $tenantId = app(TenantContext::class)->tenantIdOrDefault();
        $runIds = DB::table('ai_visibility_runs')
            ->where('engine_key', AiVisibilityRun::ENGINE_QWEN)
            ->orderBy('id')
            ->limit($count)
            ->pluck('id');

        foreach ($runIds as $runId) {
            DB::table('ai_visibility_mentions')->insert([
                'tenant_id' => $tenantId,
                'ai_visibility_run_id' => (int) $runId,
                'entity_type' => 'competitor',
                'entity_key' => $entityKey,
                'canonical_name' => $canonicalName,
                'order_index' => 2,
                'confidence' => 1.0,
                'verification_method' => 'exact',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedSource(int $runId, string $domain, int $citationIndex, bool $owned): void
    {
        DB::table('ai_visibility_sources')->insert([
            'tenant_id' => app(TenantContext::class)->tenantIdOrDefault(),
            'ai_visibility_run_id' => $runId,
            'source_type' => 'search_result',
            'citation_kind' => 'search_result',
            'citation_index' => $citationIndex,
            'is_owned_domain' => $owned,
            'url' => 'https://'.$domain.'/article',
            'domain' => $domain,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function admin(string $role): Admin
    {
        return Admin::query()->create([
            'username' => 'engine-report-'.$role.'-'.uniqid(),
            'password' => 'secret-123',
            'email' => $role.'-engine-report@example.com',
            'display_name' => 'Engine Report Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
