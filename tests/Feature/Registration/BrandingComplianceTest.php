<?php

namespace Tests\Feature\Registration;

use App\Models\Admin;
use App\Support\AdminWeb;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 平台级品牌与 AGPL 合规红线：源码入口与许可证/版权声明始终可见且没有关闭开关。
 */
class BrandingComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Branding::flush();
    }

    protected function tearDown(): void
    {
        Branding::flush();
        parent::tearDown();
    }

    public function test_source_page_is_always_public_and_shows_license_and_source_url(): void
    {
        $response = $this->get(route('source'));

        $response->assertOk();
        $response->assertSee(config('branding.source_url'));
        $response->assertSee('AGPL-3.0-or-later');
        $response->assertSee(__('registration.source.notice'));
    }

    public function test_blank_source_url_falls_back_and_can_never_be_hidden(): void
    {
        Branding::save(['source_url' => '', 'license' => '', 'name' => '']);
        Branding::flush();

        $brand = Branding::all();

        $this->assertSame(config('branding.source_url'), $brand['source_url']);
        $this->assertSame('AGPL-3.0-or-later', $brand['license']);
        $this->assertNotSame('', $brand['name']);
    }

    public function test_login_page_links_to_source_code_and_license(): void
    {
        $response = $this->get(route('admin.login'));

        $response->assertOk();
        $response->assertSee(route('source'));
        $response->assertSee('AGPL-3.0-or-later');
    }

    public function test_super_admin_can_update_platform_branding(): void
    {
        $admin = Admin::query()->create([
            'username' => 'super-owner',
            'password' => 'secret-123',
            'email' => 'super-owner@example.com',
            'display_name' => 'super-owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.branding.index'))
            ->assertOk();

        $this->post(route('admin.branding.update'), [
            'name' => 'Acme AI',
            'logo_url' => 'https://cdn.example.com/logo.png',
            'primary_color' => '#0f766e',
            'support_email' => 'help@example.com',
            'source_url' => 'https://github.com/ditoo2003/GEOFlow',
            'copyright' => 'Acme AI Team',
        ])->assertRedirect();

        Branding::flush();

        $this->assertSame('Acme AI', Branding::name());
        $this->assertSame('Acme AI', AdminWeb::siteName());
        $this->assertSame('#0f766e', Branding::primaryColor());

        $this->get(route('source'))->assertSee('Acme AI');
    }

    public function test_branding_cannot_blank_the_source_url_through_the_admin_form(): void
    {
        $admin = Admin::query()->create([
            'username' => 'super-owner',
            'password' => 'secret-123',
            'email' => 'super-owner@example.com',
            'display_name' => 'super-owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.branding.index'))
            ->post(route('admin.branding.update'), [
                'name' => 'Acme AI',
                'primary_color' => '#0f766e',
                'source_url' => '',
                'copyright' => 'Acme AI Team',
            ])->assertSessionHasErrors('source_url');

        Branding::flush();
        $this->assertNotSame('', Branding::sourceUrl());
    }
}
