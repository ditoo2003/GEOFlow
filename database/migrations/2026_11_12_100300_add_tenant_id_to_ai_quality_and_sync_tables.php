<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 补齐首版清单遗漏的文章 AI 质检/优化表与知识库切片暂存表。
 *
 * `2026_11_12_100100` 的清单以 config('tenancy.tables') 为准；
 * 首次上线时这 7 张表不在清单里，已在存量库执行过迁移，
 * 因此用本迁移单独补齐，且对全新安装（100100 已处理）保持幂等。
 */
return new class extends Migration
{
    private const TABLES = [
        'article_ai_quality_checks',
        'article_ai_quality_check_sources',
        'article_ai_quality_knowledge_bases',
        'article_ai_quality_segments',
        'article_ai_optimization_runs',
        'article_ai_optimization_steps',
        'knowledge_chunk_sync_rows',
    ];

    public function up(): void
    {
        $defaultId = (int) config('tenancy.default_tenant_id', 1);

        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $defaultId): void {
                $table->unsignedBigInteger('tenant_id')
                    ->default($defaultId)
                    ->index($tableName.'_tenant_id_index');
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tenantMatch = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $systemMatch = "current_setting('app.tenant_bypass', true) = 'on'";

        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            $quoted = '"'.str_replace('"', '""', $tableName).'"';

            DB::statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
            DB::statement(
                "CREATE POLICY tenant_isolation ON {$quoted} USING ({$systemMatch} OR {$tenantMatch}) WITH CHECK ({$systemMatch} OR {$tenantMatch})"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $tableName) {
                if (! Schema::hasTable($tableName)) {
                    continue;
                }

                $quoted = '"'.str_replace('"', '""', $tableName).'"';

                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
                DB::statement("ALTER TABLE {$quoted} DISABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$quoted} NO FORCE ROW LEVEL SECURITY");
            }
        }

        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex($tableName.'_tenant_id_index');
                $table->dropColumn('tenant_id');
            });
        }
    }
};
