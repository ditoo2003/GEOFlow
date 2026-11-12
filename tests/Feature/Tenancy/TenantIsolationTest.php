<?php

namespace Tests\Feature\Tenancy;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
    }

    public function test_model_reads_are_scoped_to_current_tenant(): void
    {
        $tenantB = $this->makeTenant('tenant-b');
        $tenantC = $this->makeTenant('tenant-c');

        // 同一 slug 在两个租户下都可以存在（唯一键已改成租户内唯一）。
        $articleB = $this->makeArticle($tenantB, 'shared-slug');
        $articleC = $this->makeArticle($tenantC, 'shared-slug');

        $this->context->useTenant((int) $tenantB->id);
        $this->assertSame([$articleB->id], Article::query()->pluck('id')->all());
        $this->assertNull(Article::query()->find($articleC->id));

        $this->context->useTenant((int) $tenantC->id);
        $this->assertSame([$articleC->id], Article::query()->pluck('id')->all());
    }

    public function test_creating_fills_tenant_id_from_context(): void
    {
        $tenantB = $this->makeTenant('tenant-b');
        $this->context->useTenant((int) $tenantB->id);

        $category = Category::query()->create(['name' => 'B 分类', 'slug' => 'b-category']);

        $this->assertSame((int) $tenantB->id, (int) $category->tenant_id);
    }

    public function test_missing_context_fails_closed(): void
    {
        $this->context->clear();

        $this->expectException(TenantContextMissingException::class);

        Article::query()->count();
    }

    public function test_platform_template_rows_are_readable_to_every_tenant(): void
    {
        $tenantB = $this->makeTenant('tenant-b');
        $tenantC = $this->makeTenant('tenant-c');

        $this->context->useSystem();
        $platform = AiModel::query()->create([
            'name' => '平台系统模型',
            'api_key' => 'platform-key',
            'model_id' => 'platform-model',
            'access_scope' => 'system_only',
            'tenant_id' => null,
        ]);

        $this->context->useTenant((int) $tenantB->id);
        $this->assertTrue(AiModel::query()->whereKey($platform->id)->exists());

        $this->context->useTenant((int) $tenantC->id);
        $this->assertTrue(AiModel::query()->whereKey($platform->id)->exists());
    }

    public function test_platform_template_rows_are_read_only_for_tenants(): void
    {
        $tenantB = $this->makeTenant('tenant-b');

        $this->context->useSystem();
        $platform = AiModel::query()->create([
            'name' => '平台系统模型',
            'api_key' => 'platform-key',
            'model_id' => 'platform-model',
            'access_scope' => 'system_only',
            'tenant_id' => null,
        ]);

        $this->context->useTenant((int) $tenantB->id);
        $model = AiModel::query()->findOrFail($platform->id);
        $model->name = '被租户篡改的模型';

        $this->expectException(AuthorizationException::class);
        $model->save();
    }

    public function test_platform_template_rows_cannot_be_deleted_by_tenants(): void
    {
        $tenantB = $this->makeTenant('tenant-b');

        $this->context->useSystem();
        $platform = AiModel::query()->create([
            'name' => '平台系统模型',
            'api_key' => 'platform-key',
            'model_id' => 'platform-model',
            'access_scope' => 'system_only',
            'tenant_id' => null,
        ]);

        $this->context->useTenant((int) $tenantB->id);

        $this->expectException(AuthorizationException::class);
        AiModel::query()->findOrFail($platform->id)->delete();
    }

    public function test_tenant_private_ai_model_is_invisible_to_other_tenants(): void
    {
        $tenantB = $this->makeTenant('tenant-b');
        $tenantC = $this->makeTenant('tenant-c');

        $this->context->useTenant((int) $tenantB->id);
        $owned = AiModel::query()->create([
            'name' => 'B 私有模型',
            'api_key' => 'b-key',
            'model_id' => 'b-model',
            'access_scope' => 'user_content',
        ]);

        $this->assertSame((int) $tenantB->id, (int) $owned->tenant_id);

        $this->context->useTenant((int) $tenantC->id);
        $this->assertFalse(AiModel::query()->whereKey($owned->id)->exists());
    }

    public function test_site_settings_are_isolated_per_tenant(): void
    {
        $tenantB = $this->makeTenant('tenant-b');
        $tenantC = $this->makeTenant('tenant-c');

        $this->context->useTenant((int) $tenantB->id);
        SiteSetting::query()->create(['setting_key' => 'site_name', 'setting_value' => 'B 站点']);

        $this->context->useTenant((int) $tenantC->id);
        SiteSetting::query()->create(['setting_key' => 'site_name', 'setting_value' => 'C 站点']);

        $this->assertSame('C 站点', (string) SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value'));

        $this->context->useTenant((int) $tenantB->id);
        $this->assertSame('B 站点', (string) SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value'));
    }

    public function test_admin_without_membership_is_rejected_when_multiple_tenants_exist(): void
    {
        $this->makeTenant('tenant-b');
        $this->makeTenant('tenant-c');

        $admin = $this->makeAdmin('orphan-admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertForbidden();
    }

    public function test_tenant_admin_only_sees_own_articles_in_list(): void
    {
        $tenantB = $this->makeTenant('tenant-b');
        $tenantC = $this->makeTenant('tenant-c');

        $articleB = $this->makeArticle($tenantB, 'b-article');
        $articleC = $this->makeArticle($tenantC, 'c-article');

        $admin = $this->makeAdmin('tenant-b-admin');
        TenantMembership::query()->create([
            'tenant_id' => $tenantB->id,
            'admin_id' => $admin->id,
            'role' => TenantMembership::ROLE_ADMIN,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee($articleB->title)
            ->assertDontSee($articleC->title);
    }

    public function test_tenant_admin_cannot_open_foreign_article(): void
    {
        $tenantB = $this->makeTenant('tenant-b');
        $tenantC = $this->makeTenant('tenant-c');

        $this->makeArticle($tenantB, 'b-article');
        $foreign = $this->makeArticle($tenantC, 'c-article');

        $admin = $this->makeAdmin('tenant-b-admin');
        TenantMembership::query()->create([
            'tenant_id' => $tenantB->id,
            'admin_id' => $admin->id,
            'role' => TenantMembership::ROLE_ADMIN,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $foreign->id]))
            ->assertNotFound();
    }

    public function test_super_admin_uses_system_context_and_sees_all_tenants(): void
    {
        $tenantB = $this->makeTenant('tenant-b');
        $tenantC = $this->makeTenant('tenant-c');

        $this->makeArticle($tenantB, 'b-article');
        $this->makeArticle($tenantC, 'c-article');

        $super = $this->makeAdmin('platform-super', 'super_admin');

        $this->actingAs($super, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk();

        $this->assertSame(2, Article::query()->count());
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'status' => 'active',
        ]);
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

    private function makeArticle(Tenant $tenant, string $slug): Article
    {
        return $this->context->runAs((int) $tenant->id, function () use ($slug): Article {
            $category = Category::query()->create(['name' => '分类 '.$slug, 'slug' => 'category-'.$slug]);
            $author = Author::query()->create(['name' => '作者 '.$slug]);

            return Article::query()->create([
                'title' => '文章 '.$slug,
                'slug' => $slug,
                'content' => '正文 '.$slug,
                'category_id' => $category->id,
                'author_id' => $author->id,
            ]);
        });
    }
}
