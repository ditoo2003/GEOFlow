<?php

namespace Tests\PostgreSQL;

use App\Database\TenantAwarePostgresConnection;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/**
 * 连接重连 / 事务回滚后的租户会话变量恢复。
 */
class TenantConnectionRecoveryTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_reconnect_restores_tenant_session_variables(): void
    {
        $this->assertInstanceOf(TenantAwarePostgresConnection::class, DB::connection());

        $tenantB = $this->makeTenant('reconnect-tenant-b');
        app(TenantContext::class)->useTenant($tenantB);

        $this->assertSame((string) $tenantB, $this->sessionTenantId());

        DB::reconnect();

        $this->assertSame((string) $tenantB, $this->sessionTenantId());
        $this->assertSame('off', $this->sessionBypass());
    }

    public function test_reconnect_restores_system_context(): void
    {
        app(TenantContext::class)->useSystem();

        DB::reconnect();

        $this->assertSame('', $this->sessionTenantId());
        $this->assertSame('on', $this->sessionBypass());
    }

    public function test_reconnect_with_unresolved_context_leaves_session_empty_and_closed(): void
    {
        app(TenantContext::class)->clear();

        DB::reconnect();

        $this->assertSame('', $this->sessionTenantId());
        $this->assertSame('off', $this->sessionBypass());
    }

    public function test_outer_transaction_rollback_resyncs_session_variables_from_memory(): void
    {
        $tenantB = $this->makeTenant('rollback-tenant-b');
        $context = app(TenantContext::class);
        $context->useTenant((int) config('tenancy.default_tenant_id', 1));

        DB::beginTransaction();
        $context->useTenant($tenantB);
        DB::rollBack();

        // 事务内的 set_config 会随回滚撤销，连接层按内存上下文重新同步，避免状态漂移。
        $this->assertSame((string) $tenantB, $this->sessionTenantId());
        $this->assertSame((string) $context->tenantId(), $this->sessionTenantId());
    }

    private function makeTenant(string $slug): int
    {
        return (int) DB::table('tenants')->insertGetId([
            'name' => $slug,
            'slug' => $slug,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sessionTenantId(): string
    {
        return (string) (DB::selectOne("select current_setting('app.tenant_id', true) as value")->value ?? '');
    }

    private function sessionBypass(): string
    {
        return (string) (DB::selectOne("select current_setting('app.tenant_bypass', true) as value")->value ?? '');
    }
}
