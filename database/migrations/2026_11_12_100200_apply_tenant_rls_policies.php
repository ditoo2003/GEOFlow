<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 为 A 类表启用 PostgreSQL 行级安全（RLS）。
 *
 * 约定：会话变量 `app.tenant_id` 由应用在进入租户上下文时设置；
 * 系统上下文（迁移/更新器/超管运维）不写租户变量，改用 `app.tenant_bypass = 'on'` 放行，
 * 避免在策略里对非数字值做类型转换。
 * 未设置上下文时策略不匹配任何行（fail-closed）。
 *
 * SQLite 测试库不支持 RLS，本迁移在非 PostgreSQL 驱动下为空操作；
 * 真正的 RLS 隔离由 tests/PostgreSQL 下的专用测试覆盖。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $platformTables = (array) config('tenancy.platform_tables', []);

        foreach ((array) config('tenancy.tables', []) as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $quoted = '"'.str_replace('"', '""', $tableName).'"';

            DB::statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");

            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
            DB::statement("DROP POLICY IF EXISTS tenant_read ON {$quoted}");
            DB::statement("DROP POLICY IF EXISTS tenant_write ON {$quoted}");

            $tenantMatch = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
            $systemMatch = "current_setting('app.tenant_bypass', true) = 'on'";

            if (in_array($tableName, $platformTables, true)) {
                DB::statement(
                    "CREATE POLICY tenant_read ON {$quoted} FOR SELECT USING (tenant_id IS NULL OR {$systemMatch} OR {$tenantMatch})"
                );
                DB::statement(
                    "CREATE POLICY tenant_write ON {$quoted} FOR ALL USING ({$systemMatch} OR {$tenantMatch}) WITH CHECK ({$systemMatch} OR {$tenantMatch})"
                );

                continue;
            }

            DB::statement(
                "CREATE POLICY tenant_isolation ON {$quoted} USING ({$systemMatch} OR {$tenantMatch}) WITH CHECK ({$systemMatch} OR {$tenantMatch})"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ((array) config('tenancy.tables', []) as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $quoted = '"'.str_replace('"', '""', $tableName).'"';

            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
            DB::statement("DROP POLICY IF EXISTS tenant_read ON {$quoted}");
            DB::statement("DROP POLICY IF EXISTS tenant_write ON {$quoted}");
            DB::statement("ALTER TABLE {$quoted} DISABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$quoted} NO FORCE ROW LEVEL SECURITY");
        }
    }
};
