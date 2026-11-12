<?php

namespace Tests\Feature\Tenancy;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantQuotaAlert;
use App\Models\TenantUsageEvent;
use App\Support\AdminTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantUsageDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_sees_only_own_usage(): void
    {
        $tenantB = $this->makeTenant('tenant-b-usage');
        $tenantC = $this->makeTenant('tenant-c-usage');
        $this->recordPublishedArticle($tenantB->id, 7);
        $this->recordPublishedArticle($tenantC->id, 42);

        $admin = $this->makeAdmin('usage-b');
        $this->joinTenant($admin, $tenantB);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.usage.index'));

        $response->assertOk();
        $response->assertSee('data-usage-tenant="'.$tenantB->id.'"', false);
        $response->assertSee('data-usage-metric="articles"', false);
        $response->assertDontSee('data-usage-tenant="'.$tenantC->id.'"', false);
        $response->assertDontSee($tenantC->name);
        $response->assertDontSee('data-usage-tenant-row', false);
    }

    public function test_super_admin_without_selected_tenant_sees_global_overview(): void
    {
        $tenantB = $this->makeTenant('tenant-b-global');
        $tenantC = $this->makeTenant('tenant-c-global');
        $this->recordPublishedArticle($tenantB->id, 3);
        $this->recordPublishedArticle($tenantC->id, 5);

        $super = $this->makeAdmin('super-global', 'super_admin');

        $response = $this->actingAs($super, 'admin')->get(route('admin.usage.index'));

        $response->assertOk();
        $response->assertSee('data-usage-scope="global"', false);
        $response->assertSee('data-usage-tenant-row="'.$tenantB->id.'"', false);
        $response->assertSee('data-usage-tenant-row="'.$tenantC->id.'"', false);
        $response->assertSee($tenantB->name);
        $response->assertSee($tenantC->name);
    }

    public function test_super_admin_can_update_tenant_plan_and_quota(): void
    {
        $tenant = $this->makeTenant('tenant-b-update');
        $super = $this->makeAdmin('super-update', 'super_admin');

        $response = $this->actingAs($super, 'admin')->from(route('admin.usage.index'))->put(
            route('admin.usage.quota.update', ['tenantId' => $tenant->id]),
            [
                'plan_code' => 'pro',
                'site_limit' => 9,
                'article_quota' => 150,
                'token_budget' => 12_000_000,
            ],
        );

        $response->assertRedirect(route('admin.usage.index'));
        $response->assertSessionHas('status');

        $tenant->refresh();
        $this->assertSame('pro', $tenant->plan_code);
        $this->assertSame(
            ['site_limit' => 9, 'article_quota' => 150, 'token_budget' => 12_000_000],
            $tenant->settings['quota'],
        );
    }

    public function test_regular_admin_cannot_update_quota(): void
    {
        $tenant = $this->makeTenant('tenant-b-forbidden');
        $admin = $this->makeAdmin('regular-forbidden');
        $this->joinTenant($admin, $tenant);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.usage.quota.update', ['tenantId' => $tenant->id]), ['plan_code' => 'enterprise'])
            ->assertForbidden();

        $this->assertNotSame('enterprise', $tenant->refresh()->plan_code);
    }

    public function test_single_tenant_deployment_hides_tenant_identity(): void
    {
        $tenant = Tenant::query()->orderBy('id')->firstOrFail();
        $tenant->forceFill(['name' => '唯一租户'])->save();
        $this->assertSame(1, Tenant::query()->count());

        $super = $this->makeAdmin('super-single', 'super_admin');
        session([AdminTenantContext::SESSION_KEY => (int) $tenant->id]);

        $response = $this->actingAs($super, 'admin')->get(route('admin.usage.index'));

        $response->assertOk();
        $response->assertSee('data-usage-scope="tenant"', false);
        $response->assertDontSee('唯一租户');
    }

    public function test_tenant_view_renders_alerts_and_recent_metering_records(): void
    {
        $tenant = $this->makeTenant('tenant-b-alerts');
        $this->recordPublishedArticle($tenant->id, 1);

        DB::table('tenant_quota_alerts')->insert([
            'tenant_id' => $tenant->id,
            'period' => now()->format('Y-m'),
            'meter' => TenantQuotaAlert::METER_PUBLISHED_ARTICLES,
            'level' => TenantQuotaAlert::LEVEL_BLOCK,
            'message' => '本月已发布文章数已达套餐上限（1 篇）。',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $admin = $this->makeAdmin('usage-alerts');
        $this->joinTenant($admin, $tenant);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.usage.index'));

        $response->assertOk();
        $response->assertSee('data-usage-alert-level="block"', false);
        $response->assertSee('本月已发布文章数已达套餐上限（1 篇）。');
        $response->assertSee('data-usage-event=', false);
    }

    public function test_super_admin_with_selected_tenant_sees_that_tenant_scope(): void
    {
        $tenantB = $this->makeTenant('tenant-b-selected');
        $tenantC = $this->makeTenant('tenant-c-selected');
        $super = $this->makeAdmin('super-selected', 'super_admin');
        session([AdminTenantContext::SESSION_KEY => (int) $tenantB->id]);

        $response = $this->actingAs($super, 'admin')->get(route('admin.usage.index'));

        $response->assertOk();
        $response->assertSee('data-usage-tenant="'.$tenantB->id.'"', false);
        $response->assertDontSee('data-usage-tenant="'.$tenantC->id.'"', false);
        $response->assertSee('data-usage-quota-form="'.$tenantB->id.'"', false);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'slug' => $slug,
            'name' => 'Tenant '.$slug,
            'status' => 'active',
            'plan_code' => 'free',
        ]);
    }

    private function makeAdmin(string $prefix, string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => $prefix.'-'.Str::lower(Str::random(6)),
            'password' => 'Password123!',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'display_name' => 'Usage operator',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function joinTenant(Admin $admin, Tenant $tenant): void
    {
        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'admin_id' => $admin->id,
            'role' => TenantMembership::ROLE_ADMIN,
        ]);
    }

    private function recordPublishedArticle(int $tenantId, int $quantity): void
    {
        DB::table('tenant_usage_events')->insert([
            'tenant_id' => $tenantId,
            'meter' => TenantUsageEvent::METER_PUBLISHED_ARTICLES,
            'quantity' => $quantity,
            'source_type' => 'article',
            'source_id' => (string) Str::ulid(),
            'idempotency_key' => 'article.published:'.Str::ulid(),
            'period' => now()->format('Y-m'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
