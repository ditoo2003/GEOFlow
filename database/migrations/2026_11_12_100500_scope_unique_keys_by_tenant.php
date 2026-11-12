<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 把"全局唯一"改为"租户内唯一"，否则第二个租户无法复用同样的 slug / 词条。
 *
 * 从 `2026_11_12_100100` 拆出，独立成段；对已经重建过唯一键的存量库保持幂等。
 */
return new class extends Migration
{
    private const UNIQUE_PAIRS = [
        ['articles', 'slug'],
        ['categories', 'slug'],
        ['lead_forms', 'slug'],
        ['ai_visibility_competitors', 'name'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('site_settings') && Schema::hasColumn('site_settings', 'tenant_id')) {
            $this->dropUniqueIndex('site_settings', 'site_settings_setting_key_unique', ['setting_key']);
            $this->dropUniqueIndex('site_settings', 'site_settings_setting_key_key');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS site_settings_tenant_id_setting_key_unique ON site_settings (tenant_id, setting_key)');
        }

        foreach (self::UNIQUE_PAIRS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $this->dropUniqueIndex($table, $table.'_'.$column.'_key', [$column]);
            $this->dropUniqueIndex($table, $table.'_'.$column.'_unique', [$column]);
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS "'.$table.'_tenant_'.$column.'_unique" ON "'
                .$table.'" (tenant_id, "'.$column.'")'
            );
        }

        // 平台模板表：tenant_id 为 NULL 的行视为平台级，唯一性按 COALESCE(tenant_id, 0) 计算。
        if (Schema::hasTable('sensitive_words') && Schema::hasColumn('sensitive_words', 'tenant_id')) {
            $this->dropUniqueIndex('sensitive_words', 'sensitive_words_word_key', ['word']);
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sensitive_words_tenant_word_unique ON sensitive_words (COALESCE(tenant_id, 0), word)');
        }

        if (Schema::hasTable('prompts') && Schema::hasColumn('prompts', 'tenant_id')) {
            $this->dropUniqueIndex('prompts', 'prompts_system_key_unique', ['system_key']);
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS prompts_tenant_system_key_unique ON prompts (COALESCE(tenant_id, 0), system_key)'
                .' WHERE system_key IS NOT NULL'
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS site_settings_tenant_id_setting_key_unique');
        if (Schema::hasTable('site_settings')) {
            $this->createGlobalUnique('site_settings', 'site_settings_setting_key_key', ['setting_key']);
        }

        foreach (self::UNIQUE_PAIRS as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement('DROP INDEX IF EXISTS "'.$table.'_tenant_'.$column.'_unique"');
            $this->createGlobalUnique($table, $table.'_'.$column.'_key', [$column]);
        }

        DB::statement('DROP INDEX IF EXISTS sensitive_words_tenant_word_unique');
        if (Schema::hasTable('sensitive_words')) {
            $this->createGlobalUnique('sensitive_words', 'sensitive_words_word_key', ['word']);
        }

        DB::statement('DROP INDEX IF EXISTS prompts_tenant_system_key_unique');
        if (Schema::hasTable('prompts')) {
            $this->createGlobalUnique('prompts', 'prompts_system_key_unique', ['system_key']);
        }
    }

    /**
     * PostgreSQL 上唯一键可能是约束也可能是裸索引，两种都尝试删除；SQLite 走 Schema 构建器。
     *
     * @param  array<int, string>  $columns
     */
    private function dropUniqueIndex(string $table, string $indexName, array $columns = []): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "'.$table.'" DROP CONSTRAINT IF EXISTS "'.$indexName.'"');
            DB::statement('DROP INDEX IF EXISTS "'.$indexName.'"');

            return;
        }

        if ($columns === []) {
            return;
        }

        try {
            Schema::table($table, static function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropUnique($columns);
            });
        } catch (Throwable) {
            // 约束已不存在或结构差异：保持幂等。
        }
    }

    /** @param array<int, string> $columns */
    private function createGlobalUnique(string $table, string $indexName, array $columns): void
    {
        $quotedColumns = implode(', ', array_map(static fn (string $column): string => '"'.$column.'"', $columns));

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS "'.$indexName.'" ON "'.$table.'" ('.$quotedColumns.')');
    }
};
