<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 租户用量计量账本与配额告警。
 *
 * - `tenant_usage_events`：对外计费事实（按"通过质检并发布"的文章篇数计量），
 *   幂等键 `(tenant_id, idempotency_key)` 保证同一篇文章重复发布只计一次。
 * - `tenant_quota_alerts`：配额阈值跨过时的一次性告警记录，幂等键
 *   `(tenant_id, period, meter, level)` 保证同一周期同一档位只告警一次。
 *
 * RLS 策略必须在创建表的迁移里直接落地：`2026_11_12_100200` 只在执行时遍历当时的表清单，
 * 事后追加的表不会自动获得策略与授权。SQLite 测试库不支持 RLS，非 PostgreSQL 时为空操作。
 */
return new class extends Migration
{
    private const TABLES = ['tenant_usage_events', 'tenant_quota_alerts'];

    public function up(): void
    {
        if (! Schema::hasTable('tenant_usage_events')) {
            Schema::create('tenant_usage_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('meter', 60);
                $table->unsignedBigInteger('quantity')->default(1);
                $table->string('source_type', 80)->nullable();
                $table->string('source_id', 120)->nullable();
                $table->string('idempotency_key', 191);
                $table->string('period', 7);
                $table->timestamp('occurred_at');
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'idempotency_key']);
                $table->index(['tenant_id', 'period', 'meter']);
            });
        }

        if (! Schema::hasTable('tenant_quota_alerts')) {
            Schema::create('tenant_quota_alerts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->string('period', 7);
                $table->string('meter', 60);
                $table->string('level', 20);
                $table->text('message');
                $table->json('context')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'period', 'meter', 'level']);
            });
        }

        $this->applyTenantIsolation();
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_quota_alerts');
        Schema::dropIfExists('tenant_usage_events');
    }

    private function applyTenantIsolation(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('tenancy.runtime_role.name', 'geoflow_app');
        $tenantMatch = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
        $systemMatch = "current_setting('app.tenant_bypass', true) = 'on'";

        foreach (self::TABLES as $tableName) {
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
            DB::statement(
                "GRANT USAGE, SELECT ON SEQUENCE \"{$tableName}_id_seq\" TO \"{$role}\""
            );
        }
    }
};
