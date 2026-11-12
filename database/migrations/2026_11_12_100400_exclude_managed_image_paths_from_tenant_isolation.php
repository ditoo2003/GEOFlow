<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `managed_image_paths` 是文件系统级登记表：`path_hash` 全局唯一，
 * 同一物理文件被所有站点/租户共享，无法在不改变业务语义的前提下按租户切分。
 * 因此将其从租户隔离清单移回平台级共享，并清理已下发的列与 RLS 策略。
 */
return new class extends Migration
{
    private const TABLE = 'managed_image_paths';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            $quoted = '"'.self::TABLE.'"';
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
            DB::statement("ALTER TABLE {$quoted} DISABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$quoted} NO FORCE ROW LEVEL SECURITY");
        }

        if (! Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropIndex(self::TABLE.'_tenant_id_index');
            $table->dropColumn('tenant_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'tenant_id')) {
            return;
        }

        $defaultId = (int) config('tenancy.default_tenant_id', 1);

        Schema::table(self::TABLE, function (Blueprint $table) use ($defaultId): void {
            $table->unsignedBigInteger('tenant_id')
                ->default($defaultId)
                ->index(self::TABLE.'_tenant_id_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tenantMatch = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $systemMatch = "current_setting('app.tenant_bypass', true) = 'on'";
        $quoted = '"'.self::TABLE.'"';

        DB::statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$quoted} USING ({$systemMatch} OR {$tenantMatch}) WITH CHECK ({$systemMatch} OR {$tenantMatch})"
        );
    }
};
