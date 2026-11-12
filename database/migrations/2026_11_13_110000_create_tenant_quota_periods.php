<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 租户月度对账快照。
 *
 * 每月一行，记录该月生效的套餐额度快照与上月超用结转：
 * - 未用完的额度月度归零（不写入下月）；
 * - 上月超用部分（`article_used - article_quota`，即运行中任务不杀导致的必要超用）
 *   结转到次月作为负额度抵扣，不做额外惩罚；
 * - 额度快照在周期首次访问时写入，结算后不随套餐变更回溯改写，保证对账可复核。
 *
 * RLS 与授权必须在本迁移内落地：`2026_11_12_100200` 只处理执行时的表清单。
 * SQLite 测试库不支持 RLS，非 PostgreSQL 时为空操作。
 */
return new class extends Migration
{
    private const TABLE = 'tenant_quota_periods';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('period', 7);
                $table->string('plan_code', 60)->nullable();
                $table->unsignedInteger('site_limit')->default(0);
                $table->unsignedInteger('article_quota')->default(0);
                $table->unsignedBigInteger('token_budget')->default(0);
                $table->unsignedInteger('article_used')->default(0);
                $table->unsignedInteger('article_carryover_in')->default(0);
                $table->unsignedBigInteger('token_used')->default(0);
                $table->unsignedBigInteger('token_carryover_in')->default(0);
                $table->timestamp('finalized_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'period']);
            });
        }

        $this->applyTenantIsolation();
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }

    private function applyTenantIsolation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('tenancy.runtime_role.name', 'geoflow_app');
        $tenantMatch = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $systemMatch = "current_setting('app.tenant_bypass', true) = 'on'";
        $quoted = '"'.self::TABLE.'"';

        DB::statement("ALTER TABLE {$quoted} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$quoted} FORCE ROW LEVEL SECURITY");

        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$quoted}");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$quoted} USING ({$systemMatch} OR {$tenantMatch}) WITH CHECK ({$systemMatch} OR {$tenantMatch})"
        );

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $role) !== 1) {
            return;
        }

        $exists = DB::selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [$role]);
        if ($exists === null) {
            return;
        }

        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE {$quoted} TO \"{$role}\"");
        DB::statement("GRANT USAGE, SELECT ON SEQUENCE \"".self::TABLE."_id_seq\" TO \"{$role}\"");
    }
};
