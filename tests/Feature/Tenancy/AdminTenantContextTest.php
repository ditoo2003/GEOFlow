<?php

namespace Tests\Feature\Tenancy;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Support\AdminTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use App\Tenancy\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminTenantContextTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
    }

    public function test_super_admin_defaults_to_system_context(): void
    {
        $this->makeTenants();

        $this->assertTrue(app(TenantResolver::class)->applyForAdmin($this->makeAdmin('root', 'super_admin')));
        $this->assertTrue($this->context->isSystem());
    }

    public function test_super_admin_selection_scopes_following_requests_to_that_tenant(): void
    {
        [$tenantB] = $this->makeTenants();
        $superAdmin = $this->makeAdmin('root', 'super_admin');
        $this->actingAs($superAdmin, 'admin');

        $this->from(route('admin.dashboard'))
            ->post(route('admin.tenant-context.switch'), ['tenant_id' => $tenantB->id])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasNoErrors();

        $this->assertSame((int) $tenantB->id, session(AdminTenantContext::SESSION_KEY));

        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertSame((int) $tenantB->id, $this->context->tenantId());
    }

    public function test_super_admin_can_switch_back_to_platform_context(): void
    {
        [$tenantB] = $this->makeTenants();
        $superAdmin = $this->makeAdmin('root', 'super_admin');
        $this->actingAs($superAdmin, 'admin');

        $this->post(route('admin.tenant-context.switch'), ['tenant_id' => $tenantB->id]);
        $this->post(route('admin.tenant-context.switch'), ['tenant_id' => null])->assertSessionHasNoErrors();

        $this->assertNull(session(AdminTenantContext::SESSION_KEY));

        $this->get(route('admin.dashboard'))->assertOk();
        $this->assertTrue($this->context->isSystem());
    }

    public function test_unknown_tenant_selection_is_rejected_with_readable_error(): void
    {
        $superAdmin = $this->makeAdmin('root', 'super_admin');
        $this->actingAs($superAdmin, 'admin');

        $this->from(route('admin.dashboard'))
            ->post(route('admin.tenant-context.switch'), ['tenant_id' => 999999])
            ->assertSessionHasErrors('tenant_id');

        $this->assertNull(session(AdminTenantContext::SESSION_KEY));
    }

    public function test_ordinary_admin_cannot_switch_tenant_context(): void
    {
        [$tenantB, $tenantC] = $this->makeTenants();
        $admin = $this->makeAdmin('tenant-b-admin');
        $this->attachMembership($admin, $tenantB);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.tenant-context.switch'), ['tenant_id' => $tenantC->id])
            ->assertForbidden();

        $this->assertNull(session(AdminTenantContext::SESSION_KEY));
    }

    public function test_ordinary_admin_session_selection_is_ignored(): void
    {
        [$tenantB, $tenantC] = $this->makeTenants();
        $admin = $this->makeAdmin('tenant-b-admin');
        $this->attachMembership($admin, $tenantB);

        session([AdminTenantContext::SESSION_KEY => (int) $tenantC->id]);

        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk();

        $this->assertSame((int) $tenantB->id, $this->context->tenantId());
    }

    public function test_site_write_without_selection_fails_closed_in_multi_tenant_deployment(): void
    {
        $this->makeTenants();
        $this->context->useSystem();

        $this->expectException(TenantContextMissingException::class);
        $this->expectExceptionMessage('归属租户');

        $this->context->tenantIdForSite('primary');
    }

    public function test_site_write_keeps_working_in_single_tenant_deployment(): void
    {
        $this->context->useSystem();

        $this->assertSame(
            (int) config('tenancy.default_tenant_id', 1),
            $this->context->tenantIdForSite('primary')
        );
    }

    public function test_site_write_uses_the_selected_tenant(): void
    {
        [$tenantB] = $this->makeTenants();
        $this->context->useTenant((int) $tenantB->id);

        $this->assertSame((int) $tenantB->id, $this->context->tenantIdForSite('primary'));
    }

    public function test_missing_site_tenant_is_rendered_as_readable_chinese_error(): void
    {
        $this->makeTenants();

        Route::middleware('web')->get('/__tenant-site-write', function (): int {
            app(TenantContext::class)->useSystem();

            return app(TenantContext::class)->tenantIdForSite('primary');
        });

        $response = $this->get('/__tenant-site-write');

        $response->assertStatus(422);
        $this->assertStringContainsString('租户', (string) $response->getContent());
    }

    /**
     * @return array{0: Tenant, 1: Tenant}
     */
    private function makeTenants(): array
    {
        return [
            Tenant::query()->create(['name' => 'tenant-b', 'slug' => 'tenant-b', 'status' => 'active']),
            Tenant::query()->create(['name' => 'tenant-c', 'slug' => 'tenant-c', 'status' => 'active']),
        ];
    }

    private function makeAdmin(string $username, string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function attachMembership(Admin $admin, Tenant $tenant): void
    {
        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'admin_id' => $admin->id,
            'role' => TenantMembership::ROLE_ADMIN,
        ]);
    }
}
