<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 多引擎 AI 可见性采集契约落库。
 *
 * - `ai_visibility_runs` 补引擎身份、样本批次、联网标记、成本与口径字段；
 *   历史数据统一标记 `is_legacy = true`、`collection_method = 'legacy'`，不进入新聚合口径。
 * - `ai_visibility_sources` 补引用类型/序号/自有域名/命中实体，供信源维度统计。
 * - `ai_visibility_competitors` 补 `domains`，用于信源归属判定。
 * - 新表（样本批次、提及实体）建表即落地 RLS 与运行时角色授权：
 *   `2026_11_12_100200` 只遍历执行当时的表清单，事后追加的表不会自动获得策略。
 */
return new class extends Migration
{
    private const NEW_TABLES = ['ai_visibility_sample_batches', 'ai_visibility_mentions'];

    /** @var array<string,array{0:string,1:string}> 旧 provider_type => [engine_key, engine_variant] */
    private const LEGACY_ENGINE_MAP = [
        'doubao_ark_responses' => ['doubao', 'api'],
        'doubao_search_custom' => ['doubao_search', 'api'],
        'deepseek_analysis' => ['deepseek_analysis', 'api'],
    ];

    public function up(): void
    {
        $this->addRunContractColumns();
        $this->addSourceCitationColumns();
        $this->addCompetitorDomainColumn();
        $this->createSampleBatchTable();
        $this->createMentionTable();
        $this->markLegacyRuns();
        $this->applyTenantIsolation();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_mentions');
        Schema::dropIfExists('ai_visibility_sample_batches');

        if (Schema::hasTable('ai_visibility_competitors') && Schema::hasColumn('ai_visibility_competitors', 'domains')) {
            Schema::table('ai_visibility_competitors', function (Blueprint $table): void {
                $table->dropColumn('domains');
            });
        }

        if (Schema::hasTable('ai_visibility_sources')) {
            Schema::table('ai_visibility_sources', function (Blueprint $table): void {
                $table->dropIndex('ai_visibility_sources_citation_idx');
                foreach (['citation_kind', 'citation_index', 'is_owned_domain', 'matched_entity_key'] as $column) {
                    if (Schema::hasColumn('ai_visibility_sources', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('ai_visibility_runs')) {
            Schema::table('ai_visibility_runs', function (Blueprint $table): void {
                foreach (['ai_visibility_runs_tenant_engine_created_idx', 'ai_visibility_runs_batch_idx', 'ai_visibility_runs_agg_idx'] as $index) {
                    $table->dropIndex($index);
                }
                foreach ([
                    'engine_key', 'engine_variant', 'collection_method', 'region',
                    'prompt_template_key', 'prompt_template_version', 'sample_batch_id',
                    'sample_index', 'attempt_no', 'search_enabled', 'cost_estimate_micros',
                    'http_status', 'is_legacy',
                ] as $column) {
                    if (Schema::hasColumn('ai_visibility_runs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }

    private function addRunContractColumns(): void
    {
        if (! Schema::hasTable('ai_visibility_runs')) {
            return;
        }

        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_visibility_runs', 'engine_key')) {
                $table->string('engine_key', 60)->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'engine_variant')) {
                $table->string('engine_variant', 20)->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'collection_method')) {
                $table->string('collection_method', 20)->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'region')) {
                $table->string('region', 20)->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'prompt_template_key')) {
                $table->string('prompt_template_key', 60)->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'prompt_template_version')) {
                $table->string('prompt_template_version', 20)->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'sample_batch_id')) {
                $table->unsignedBigInteger('sample_batch_id')->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'sample_index')) {
                $table->unsignedSmallInteger('sample_index')->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'attempt_no')) {
                $table->unsignedSmallInteger('attempt_no')->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'search_enabled')) {
                // null 表示历史样本未记录联网状态；新样本必须显式写 true/false。
                $table->boolean('search_enabled')->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'cost_estimate_micros')) {
                $table->unsignedBigInteger('cost_estimate_micros')->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'http_status')) {
                $table->unsignedSmallInteger('http_status')->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_runs', 'is_legacy')) {
                $table->boolean('is_legacy')->default(true);
            }
        });

        if (! Schema::hasIndex('ai_visibility_runs', 'ai_visibility_runs_tenant_engine_created_idx')) {
            Schema::table('ai_visibility_runs', function (Blueprint $table): void {
                $table->index(['tenant_id', 'engine_key', 'created_at'], 'ai_visibility_runs_tenant_engine_created_idx');
            });
        }
        if (! Schema::hasIndex('ai_visibility_runs', 'ai_visibility_runs_batch_idx')) {
            Schema::table('ai_visibility_runs', function (Blueprint $table): void {
                $table->index(['tenant_id', 'sample_batch_id'], 'ai_visibility_runs_batch_idx');
            });
        }
        if (! Schema::hasIndex('ai_visibility_runs', 'ai_visibility_runs_agg_idx')) {
            Schema::table('ai_visibility_runs', function (Blueprint $table): void {
                $table->index(
                    ['tenant_id', 'engine_key', 'keyword_hash', 'search_enabled'],
                    'ai_visibility_runs_agg_idx'
                );
            });
        }
    }

    private function addSourceCitationColumns(): void
    {
        if (! Schema::hasTable('ai_visibility_sources')) {
            return;
        }

        Schema::table('ai_visibility_sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('ai_visibility_sources', 'citation_kind')) {
                $table->string('citation_kind', 30)->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_sources', 'citation_index')) {
                $table->unsignedSmallInteger('citation_index')->nullable();
            }
            if (! Schema::hasColumn('ai_visibility_sources', 'is_owned_domain')) {
                $table->boolean('is_owned_domain')->default(false);
            }
            if (! Schema::hasColumn('ai_visibility_sources', 'matched_entity_key')) {
                $table->string('matched_entity_key', 120)->nullable();
            }
        });

        if (! Schema::hasIndex('ai_visibility_sources', 'ai_visibility_sources_citation_idx')) {
            Schema::table('ai_visibility_sources', function (Blueprint $table): void {
                $table->index(['ai_visibility_run_id', 'citation_index'], 'ai_visibility_sources_citation_idx');
            });
        }
    }

    private function addCompetitorDomainColumn(): void
    {
        if (! Schema::hasTable('ai_visibility_competitors') || Schema::hasColumn('ai_visibility_competitors', 'domains')) {
            return;
        }

        Schema::table('ai_visibility_competitors', function (Blueprint $table): void {
            $table->json('domains')->nullable();
        });
    }

    private function createSampleBatchTable(): void
    {
        if (Schema::hasTable('ai_visibility_sample_batches')) {
            return;
        }

        Schema::create('ai_visibility_sample_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('keyword_hash', 64);
            $table->string('engine_key', 60);
            $table->string('engine_variant', 20)->default('api');
            $table->string('region', 20)->default('global');
            $table->string('locale', 20)->default('zh_CN');
            $table->string('prompt_template_key', 60);
            $table->string('prompt_template_version', 20)->default('v1');
            // 联网与非联网样本分线聚合：同批次内该标记必须一致。
            $table->boolean('search_enabled')->default(false);
            $table->date('period');
            $table->unsignedSmallInteger('planned_samples')->default(0);
            $table->unsignedSmallInteger('completed_samples')->default(0);
            $table->unsignedSmallInteger('failed_samples')->default(0);
            $table->string('status', 20)->default('open');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                [
                    'tenant_id', 'keyword_hash', 'engine_key', 'engine_variant', 'region',
                    'locale', 'prompt_template_version', 'search_enabled', 'period',
                ],
                'ai_visibility_batch_unique'
            );
            $table->index(['tenant_id', 'engine_key', 'period'], 'ai_visibility_batch_lookup_idx');
        });
    }

    private function createMentionTable(): void
    {
        if (Schema::hasTable('ai_visibility_mentions')) {
            return;
        }

        Schema::create('ai_visibility_mentions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('ai_visibility_run_id')->constrained('ai_visibility_runs')->cascadeOnDelete();
            $table->string('entity_type', 20);
            $table->string('entity_key', 120);
            $table->string('canonical_name', 160);
            $table->string('matched_alias', 160)->nullable();
            $table->unsignedInteger('char_offset')->nullable();
            $table->unsignedSmallInteger('sentence_index')->nullable();
            $table->unsignedSmallInteger('order_index')->nullable();
            $table->text('context_excerpt')->nullable();
            $table->decimal('confidence', 4, 3)->default(1);
            $table->string('verification_method', 30)->default('exact');
            $table->string('sentiment', 20)->nullable();
            $table->timestamps();

            $table->index(['ai_visibility_run_id'], 'ai_visibility_mentions_run_idx');
            $table->index(['tenant_id', 'entity_key', 'created_at'], 'ai_visibility_mentions_entity_idx');
        });
    }

    /**
     * 历史豆包/DeepSeek 数据只补身份与 `legacy` 标记，不回填不可得的引用与联网状态。
     */
    private function markLegacyRuns(): void
    {
        if (! Schema::hasTable('ai_visibility_runs')) {
            return;
        }

        foreach (self::LEGACY_ENGINE_MAP as $providerType => [$engineKey, $variant]) {
            DB::table('ai_visibility_runs')
                ->where('provider_type', $providerType)
                ->whereNull('engine_key')
                ->update([
                    'engine_key' => $engineKey,
                    'engine_variant' => $variant,
                    'collection_method' => 'legacy',
                    'is_legacy' => true,
                ]);
        }
    }

    private function applyTenantIsolation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('tenancy.runtime_role.name', 'geoflow_app');
        $tenantMatch = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $systemMatch = "current_setting('app.tenant_bypass', true) = 'on'";

        foreach (self::NEW_TABLES as $tableName) {
            $quoted = '"'.str_replace('"', '""', $tableName).'"';

            DB::statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");

            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
            DB::statement(
                "CREATE POLICY tenant_isolation ON {$quoted} USING ({$systemMatch} OR {$tenantMatch}) WITH CHECK ({$systemMatch} OR {$tenantMatch})"
            );

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $role) !== 1) {
                continue;
            }

            $exists = DB::selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [$role]);
            if ($exists === null) {
                continue;
            }

            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE {$quoted} TO \"{$role}\"");
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE \"{$tableName}_id_seq\" TO \"{$role}\"");
        }
    }
};
