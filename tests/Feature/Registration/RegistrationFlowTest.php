<?php

namespace Tests\Feature\Registration;

use App\Mail\RegistrationVerificationMail;
use App\Models\Admin;
use App\Models\RegistrationInvitation;
use App\Models\RegistrationSignup;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\Registration\RegistrationService;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * 自助注册：邀请制 / 公开注册共用同一条租户创建链路。
 */
class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantBaseline;

    private int $adminBaseline;

    protected function setUp(): void
    {
        parent::setUp();
        Branding::flush();
        $this->tenantBaseline = Tenant::query()->count();
        $this->adminBaseline = Admin::query()->count();
        RateLimiter::clear('registration:ip:127.0.0.1');
        foreach (['alice@example.com', 'bob@example.com'] as $email) {
            RateLimiter::clear('registration:email:'.$email);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Branding::flush();
        parent::tearDown();
    }

    public function test_invitation_mode_creates_tenant_admin_and_membership(): void
    {
        config(['registration.mode' => 'invite']);
        $owner = $this->makeAdmin('super-owner', 'super_admin');
        [, $token] = app(RegistrationService::class)->createInvitation([
            'email' => 'alice@example.com',
            'tenant_name' => 'Acme Workspace',
        ], (int) $owner->id);

        $response = $this->post(route('register.store'), $this->payload(['invite' => $token]));

        $response->assertRedirect(route('admin.dashboard'));

        $admin = Admin::query()->where('email', 'alice@example.com')->firstOrFail();
        $tenant = Tenant::query()->where('name', 'Acme Workspace')->firstOrFail();

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame((string) config('billing.default_plan'), (string) $tenant->plan_code);
        $this->assertSame('active', (string) $tenant->status);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenant->id,
            'admin_id' => $admin->id,
            'role' => TenantMembership::ROLE_OWNER,
        ]);

        $invitation = RegistrationInvitation::query()->firstOrFail();
        $this->assertNotNull($invitation->used_at);
        $this->assertSame((int) $tenant->id, (int) $invitation->used_tenant_id);
        $this->assertDatabaseCount('registration_signups', 0);
    }

    public function test_invitation_link_is_single_use(): void
    {
        config(['registration.mode' => 'invite']);
        $owner = $this->makeAdmin('super-owner', 'super_admin');
        [, $token] = app(RegistrationService::class)->createInvitation([
            'email' => 'alice@example.com',
        ], (int) $owner->id);

        $this->post(route('register.store'), $this->payload(['invite' => $token]));
        auth('admin')->logout();

        $second = $this->from(route('register', ['invite' => $token]))
            ->post(route('register.store'), $this->payload(['invite' => $token, 'email' => 'bob@example.com']));

        $second->assertRedirect(route('register', ['invite' => $token]));
        $second->assertSessionHasErrors('registration');
        $this->assertSame($this->tenantBaseline + 1, Tenant::query()->count());
    }

    public function test_invalid_invitation_is_rejected(): void
    {
        config(['registration.mode' => 'invite']);

        $response = $this->from(route('register'))
            ->post(route('register.store'), $this->payload(['invite' => 'not-a-real-token']));

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('registration');
        $this->assertSame($this->tenantBaseline, Tenant::query()->count());
    }

    public function test_public_mode_requires_email_verification_before_creating_a_tenant(): void
    {
        config(['registration.mode' => 'public']);
        Mail::fake();

        $this->post(route('register.store'), $this->payload())
            ->assertSee(__('registration.result.check_email_title'));

        $this->assertSame($this->tenantBaseline, Tenant::query()->count());
        $this->assertSame($this->adminBaseline, Admin::query()->count());

        $signup = RegistrationSignup::query()->where('email', 'alice@example.com')->firstOrFail();
        $this->assertNull($signup->consumed_at);
        $this->assertTrue($signup->expires_at->isFuture());

        $token = null;
        Mail::assertSent(RegistrationVerificationMail::class, function (RegistrationVerificationMail $mail) use (&$token): bool {
            $token = $mail->verificationToken;

            return true;
        });
        $this->assertIsString($token);

        $this->get('/register/verify/'.$token)->assertRedirect(route('admin.dashboard'));

        $admin = Admin::query()->where('email', 'alice@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame($this->tenantBaseline + 1, Tenant::query()->count());
        $this->assertNotNull($signup->refresh()->consumed_at);

        // 同一链接不可二次使用。
        auth('admin')->logout();
        $this->get('/register/verify/'.$token)->assertSee(__('registration.result.verify_failed_title'));
        $this->assertSame($this->tenantBaseline + 1, Tenant::query()->count());
    }

    public function test_public_mode_rate_limits_by_ip(): void
    {
        config(['registration.mode' => 'public']);
        Mail::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('register.store'), $this->payload(['email' => 'user'.$i.'@example.com']))
                ->assertStatus(200);
        }

        $this->post(route('register.store'), $this->payload(['email' => 'user6@example.com']))
            ->assertStatus(429);
    }

    public function test_public_mode_rate_limits_by_email(): void
    {
        config(['registration.mode' => 'public']);
        Mail::fake();

        for ($i = 1; $i <= 3; $i++) {
            $this->post(route('register.store'), $this->payload())->assertStatus(200);
        }

        $this->post(route('register.store'), $this->payload())->assertStatus(429);
    }

    public function test_public_mode_rejects_disposable_email_domains(): void
    {
        config(['registration.mode' => 'public']);
        Mail::fake();

        $response = $this->from(route('register'))->post(route('register.store'), $this->payload([
            'email' => 'someone@mailinator.com',
        ]));

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('registration');
        Mail::assertNothingSent();
        $this->assertDatabaseCount('registration_signups', 0);
    }

    public function test_public_verification_link_expires_after_sixty_minutes(): void
    {
        config(['registration.mode' => 'public']);
        Mail::fake();

        $this->post(route('register.store'), $this->payload());

        $token = null;
        Mail::assertSent(RegistrationVerificationMail::class, function (RegistrationVerificationMail $mail) use (&$token): bool {
            $token = $mail->verificationToken;

            return true;
        });

        Carbon::setTestNow(now()->addMinutes(61));

        $this->get('/register/verify/'.$token)->assertSee(__('registration.result.verify_failed_title'));
        $this->assertSame($this->tenantBaseline, Tenant::query()->count());
    }

    public function test_resend_sends_a_new_verification_email(): void
    {
        config(['registration.mode' => 'public']);
        Mail::fake();

        $this->post(route('register.store'), $this->payload());
        Mail::assertSentCount(1);

        $this->post(route('register.resend'), ['email' => 'alice@example.com'])
            ->assertSee(__('registration.result.resend_title'));

        Mail::assertSentCount(2);
        $this->assertSame(2, (int) RegistrationSignup::query()->where('email', 'alice@example.com')->value('sent_count'));
    }

    public function test_only_super_admin_can_manage_registration_invitations(): void
    {
        $superAdmin = $this->makeAdmin('super-owner', 'super_admin');
        $tenantAdmin = $this->makeAdmin('tenant-admin');

        $this->actingAs($tenantAdmin, 'admin')
            ->get(route('admin.registration-invitations.index'))
            ->assertForbidden();

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.registration-invitations.index'))
            ->assertOk();

        $this->post(route('admin.registration-invitations.store'), [
            'email' => 'invitee@example.com',
            'tenant_name' => 'Invited Space',
        ])->assertRedirect()->assertSessionHas('invitation_url');

        $this->assertDatabaseHas('registration_invitations', [
            'email' => 'invitee@example.com',
            'tenant_name' => 'Invited Space',
        ]);
    }

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alice',
            'tenant_name' => 'Acme Workspace',
            'email' => 'alice@example.com',
            'password' => 'secret-123',
            'password_confirmation' => 'secret-123',
        ], $overrides);
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
}
