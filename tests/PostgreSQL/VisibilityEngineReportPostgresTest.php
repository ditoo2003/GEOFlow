<?php

namespace Tests\PostgreSQL;

use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\AiVisibilityKeywordNormalizer;
use App\Services\GeoFlow\AiVisibility\VisibilityEngineReportService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 聚合 SQL 在真实 PostgreSQL 上的兼容性验证（GROUP BY 布尔列、COUNT DISTINCT CASE）。
 */
class VisibilityEngineReportPostgresTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_report_aggregates_engine_cells_on_postgresql(): void
    {
        $tenantId = (int) config('tenancy.default_tenant_id', 1);
        $keyword = 'AI 搜索推荐';
        $keywordHash = AiVisibilityKeywordNormalizer::hash($keyword);

        for ($index = 0; $index < 30; $index++) {
            $runId = DB::table('ai_visibility_runs')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'keyword' => $keyword,
                'keyword_hash' => $keywordHash,
                'prompt' => $keyword,
                'provider_type' => AiVisibilityRun::PROVIDER_ENGINE_SAMPLE,
                'provider_key' => AiVisibilityRun::ENGINE_QWEN,
                'engine_key' => AiVisibilityRun::ENGINE_QWEN,
                'engine_variant' => AiVisibilityRun::VARIANT_API,
                'collection_method' => AiVisibilityRun::METHOD_API,
                'region' => 'global',
                'locale' => 'zh_CN',
                'search_enabled' => true,
                'is_legacy' => false,
                'status' => AiVisibilityRun::STATUS_COMPLETED,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('ai_visibility_mentions')->insert([
                'tenant_id' => $tenantId,
                'ai_visibility_run_id' => $runId,
                'entity_type' => 'brand',
                'entity_key' => 'geoflow',
                'canonical_name' => 'GEOFlow',
                'order_index' => 1,
                'confidence' => 1.0,
                'verification_method' => 'exact',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $report = app(VisibilityEngineReportService::class)->report();
        $qwen = collect($report['engines'])->firstWhere('engine_key', AiVisibilityRun::ENGINE_QWEN);

        $this->assertNotNull($qwen);
        $this->assertCount(1, $qwen['cells']);
        $this->assertTrue($qwen['cells'][0]['ready']);
        $this->assertSame(30, $qwen['cells'][0]['valid_samples']);
        $this->assertSame(1.0, $qwen['cells'][0]['mention_rate']);
        $this->assertSame(1.0, $qwen['cells'][0]['first_position_rate']);
    }
}
