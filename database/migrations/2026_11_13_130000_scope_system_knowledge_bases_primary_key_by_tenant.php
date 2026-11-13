<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `system_knowledge_bases` 的主键从单列 `system_key` 改为 `(tenant_id, system_key)`。
 *
 * 系统知识库绑定按租户各存一行；原单列主键会让第二个租户写入同一 `system_key` 时
 * 触发 `duplicate key value violates unique constraint "system_knowledge_bases_pkey"`。
 * SQLite 仅用于测试且不启用 RLS，保持原结构；多租户行为由 PostgreSQL 覆盖。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('system_knowledge_bases')) {
            return;
        }

        DB::statement('ALTER TABLE system_knowledge_bases DROP CONSTRAINT IF EXISTS system_knowledge_bases_pkey');
        DB::statement('ALTER TABLE system_knowledge_bases ADD CONSTRAINT system_knowledge_bases_pkey PRIMARY KEY (tenant_id, system_key)');
        DB::statement('CREATE INDEX IF NOT EXISTS system_knowledge_bases_system_key_index ON system_knowledge_bases (system_key)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS system_knowledge_bases_system_key_index');
        DB::statement('ALTER TABLE system_knowledge_bases DROP CONSTRAINT IF EXISTS system_knowledge_bases_pkey');
        DB::statement('ALTER TABLE system_knowledge_bases ADD CONSTRAINT system_knowledge_bases_pkey PRIMARY KEY (system_key)');
    }
};
