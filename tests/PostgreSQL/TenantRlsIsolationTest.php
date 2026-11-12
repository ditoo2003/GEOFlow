<?php

namespace Tests\PostgreSQL;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PostgreSQL RLS 隔离验证。
 *
 * SQLite 测试库不支持 RLS，因此这里用真实 PG：
 * - 断言 A 类表已启用并强制 RLS；
 * - 切换到非超级用户角色后，行级策略必须阻断跨租户读写；
 * - 未设置 `app.tenant_id` 时查询结果为空（fail-closed）。
 */
class TenantRlsIsolationTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    private const PROBE_ROLE = 'geoflow_rls_probe';

    public function test_every_configured_table_has_tenant_id_column(): void
    {
        $missing = [];

        foreach ((array) config('tenancy.tables') as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                $missing[] = $table;
            }
        }

        $this->assertSame([], $missing, '以下配置表缺少 tenant_id 列：'.implode(', ', $missing));
    }

    public function test_scoped_tables_have_forced_row_level_security(): void
    {
        $rows = DB::select(
            "select relname, relrowsecurity, relforcerowsecurity from pg_class where relkind = 'r' and relname in (?, ?, ?, ?)",
            ['articles', 'site_settings', 'knowledge_chunks', 'ai_models']
        );

        $found = collect($rows)->keyBy('relname');
        $this->assertCount(4, $found);

        foreach (['articles', 'site_settings', 'knowledge_chunks', 'ai_models'] as $table) {
            $this->assertTrue($found->has($table), $table.' 缺少 RLS 配置');
            $this->assertTrue((bool) $found[$table]->relrowsecurity, $table.' 未启用 RLS');
            $this->assertTrue((bool) $found[$table]->relforcerowsecurity, $table.' 未强制 RLS');
        }
    }

    public function test_non_superuser_role_only_reads_current_tenant_rows(): void
    {
        $this->requireSuperuserToCreateProbeRole();

        DB::statement('CREATE ROLE '.self::PROBE_ROLE.' NOLOGIN');
        DB::statement('GRANT USAGE ON SCHEMA public TO '.self::PROBE_ROLE);
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO '.self::PROBE_ROLE);
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO '.self::PROBE_ROLE);

        $tenantB = DB::table('tenants')->insertGetId([
            'name' => '租户 B',
            'slug' => 'rls-tenant-b',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('categories')->insert([
            ['tenant_id' => (int) config('tenancy.default_tenant_id'), 'name' => '默认租户分类', 'slug' => 'default-category', 'created_at' => now()],
            ['tenant_id' => $tenantB, 'name' => '租户 B 分类', 'slug' => 'tenant-b-category', 'created_at' => now()],
        ]);

        try {
            DB::statement('SET ROLE '.self::PROBE_ROLE);

            $this->setSessionTenant((string) $tenantB);
            $this->assertSame(1, DB::table('categories')->count());
            $this->assertSame('tenant-b-category', (string) DB::table('categories')->value('slug'));

            // 未设置上下文时必须看到 0 行。
            DB::statement("select set_config('app.tenant_id', '', false)");
            $this->assertSame(0, DB::table('categories')->count());

            // 显式系统上下文允许跨租户（仅供迁移/运维使用）。
            DB::statement("select set_config('app.tenant_id', '', false)");
            DB::statement("select set_config('app.tenant_bypass', 'on', false)");
            $this->assertSame(2, DB::table('categories')->count());

            // 跨租户写入必须被 WITH CHECK 拒绝。
            $this->setSessionTenant((string) $tenantB);
            $this->expectException(QueryException::class);
            DB::table('categories')->insert([
                'tenant_id' => (int) config('tenancy.default_tenant_id'),
                'name' => '越权写入',
                'slug' => 'cross-tenant-write-'.Str::random(6),
                'created_at' => now(),
            ]);
        } finally {
            DB::statement('RESET ROLE');
            DB::statement('DROP OWNED BY '.self::PROBE_ROLE);
            DB::statement('DROP ROLE IF EXISTS '.self::PROBE_ROLE);
        }
    }

    public function test_platform_template_rows_are_read_only_for_tenants(): void
    {
        $this->requireSuperuserToCreateProbeRole();

        DB::statement('CREATE ROLE '.self::PROBE_ROLE.' NOLOGIN');
        DB::statement('GRANT USAGE ON SCHEMA public TO '.self::PROBE_ROLE);
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO '.self::PROBE_ROLE);
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO '.self::PROBE_ROLE);

        $tenantB = DB::table('tenants')->insertGetId([
            'name' => '租户 B',
            'slug' => 'rls-tenant-b-platform',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_models')->insert([
            'tenant_id' => null,
            'name' => '平台系统模型',
            'api_key' => 'platform-key',
            'model_id' => 'platform-model',
            'access_scope' => 'system_only',
        ]);

        try {
            DB::statement('SET ROLE '.self::PROBE_ROLE);
            $this->setSessionTenant((string) $tenantB);

            $this->assertSame(1, DB::table('ai_models')->count());

            $this->expectException(QueryException::class);
            DB::table('ai_models')->insert([
                'tenant_id' => null,
                'name' => '租户伪造平台模型',
                'api_key' => 'tenant-key',
                'model_id' => 'tenant-model',
                'access_scope' => 'system_only',
            ]);
        } finally {
            DB::statement('RESET ROLE');
            DB::statement('DROP OWNED BY '.self::PROBE_ROLE);
            DB::statement('DROP ROLE IF EXISTS '.self::PROBE_ROLE);
        }
    }

    private function setSessionTenant(string $tenantId): void
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [$tenantId]);
        DB::statement("select set_config('app.tenant_bypass', 'off', false)");
    }

    private function requireSuperuserToCreateProbeRole(): void
    {
        $isSuper = DB::selectOne('select rolsuper from pg_roles where rolname = current_user')?->rolsuper;
        $isSuper = in_array($isSuper, [true, 't', 'true', 1, '1'], true);
        if (! $isSuper) {
            $this->markTestSkipped('测试连接不是超级用户，无法创建 RLS 探针角色。');
        }
    }
}
