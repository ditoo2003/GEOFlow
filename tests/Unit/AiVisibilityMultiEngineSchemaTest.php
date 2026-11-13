<?php

namespace Tests\Unit;

use App\Services\GeoFlow\AiVisibility\Contract\VisibilityMention;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilityPromptTemplates;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySample;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySampleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AiVisibilityMultiEngineSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_table_carries_the_engine_sample_contract(): void
    {
        foreach ([
            'engine_key', 'engine_variant', 'collection_method', 'region',
            'prompt_template_key', 'prompt_template_version', 'sample_batch_id',
            'sample_index', 'attempt_no', 'search_enabled', 'cost_estimate_micros',
            'http_status', 'is_legacy',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('ai_visibility_runs', $column),
                'ai_visibility_runs 缺少列：'.$column
            );
        }
    }

    public function test_sources_and_competitors_carry_citation_and_domain_columns(): void
    {
        foreach (['citation_kind', 'citation_index', 'is_owned_domain', 'matched_entity_key'] as $column) {
            $this->assertTrue(Schema::hasColumn('ai_visibility_sources', $column), 'ai_visibility_sources 缺少列：'.$column);
        }

        $this->assertTrue(Schema::hasColumn('ai_visibility_competitors', 'domains'));
    }

    public function test_new_sample_tables_are_tenant_scoped(): void
    {
        foreach (['ai_visibility_sample_batches', 'ai_visibility_mentions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' 未创建');
            $this->assertTrue(Schema::hasColumn($table, 'tenant_id'), $table.' 缺少 tenant_id');
        }
    }

    public function test_runs_default_to_legacy_until_an_engine_sample_explicitly_writes(): void
    {
        DB::table('ai_visibility_runs')->insert([
            'uuid' => 'legacy-run-'.uniqid(),
            'keyword' => '示例关键词',
            'provider_type' => 'doubao_ark_responses',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue((bool) DB::table('ai_visibility_runs')->value('is_legacy'));
    }

    public function test_sample_contract_marks_online_and_offline_samples_separately(): void
    {
        foreach ([true, false] as $searchEnabled) {
            $sample = new VisibilitySample(
                engineKey: 'qwen',
                engineVariant: 'api',
                collectionMethod: 'api',
                modelKey: 'qwen-max',
                request: VisibilitySampleRequest::forKeyword('示例关键词', searchEnabled: $searchEnabled),
                status: VisibilitySample::STATUS_COMPLETED,
                answerText: '示例回答',
            );

            $attributes = $sample->toRunAttributes();

            $this->assertSame($searchEnabled, $attributes['search_enabled']);
            $this->assertFalse($attributes['is_legacy']);
            $this->assertSame('qwen', $attributes['engine_key']);
            $this->assertSame(VisibilityPromptTemplates::version(VisibilityPromptTemplates::KEY_QUESTION), $attributes['prompt_template_version']);
        }
    }

    public function test_mention_contract_orders_entities_for_first_place_rate(): void
    {
        $mention = new VisibilityMention(
            entityType: 'brand',
            entityKey: 'geoflow',
            canonicalName: 'GEOFlow',
            matchedAlias: 'GEOFlow',
            orderIndex: 2,
        );

        $attributes = $mention->toDatabaseAttributes();

        $this->assertSame(2, $attributes['order_index']);
        $this->assertSame('brand', $attributes['entity_type']);
        $this->assertSame(1.0, $attributes['confidence']);
    }

    public function test_prompt_templates_are_versioned_and_reject_unknown_keys(): void
    {
        $this->assertSame(
            '请回答这个问题：GEOFlow。请用简洁要点回答，并在有来源时附上引用链接。',
            VisibilityPromptTemplates::render(VisibilityPromptTemplates::KEY_QUESTION, 'GEOFlow')
        );
        $this->assertSame('v1', VisibilityPromptTemplates::version(VisibilityPromptTemplates::KEY_EN_QUESTION));

        $this->expectException(InvalidArgumentException::class);
        VisibilityPromptTemplates::version('unknown_template');
    }
}
