<?php

namespace App\Services\Registration;

use App\Mail\RegistrationVerificationMail;
use App\Models\Admin;
use App\Models\RegistrationInvitation;
use App\Models\RegistrationSignup;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * 自助注册（邀请制 / 公开注册共用同一条租户创建链路）。
 *
 * - 邀请制：邀请链接即校验，注册后立即创建租户与管理员，不发验证邮件；
 * - 公开注册：先落 `registration_signups` 待验证申请，邮箱验证通过后才创建租户；
 *   IP 5 次/小时、邮箱 3 封/24h 限流，验证链接 60 分钟，一次性邮箱域名黑名单，
 *   可选 Turnstile（默认关闭）。
 *
 * 新租户默认套餐取 `config('billing.default_plan')`，全链路不写死。
 */
final class RegistrationService
{
    public function __construct(
        private readonly TurnstileVerifier $turnstile,
    ) {}

    public function mode(): string
    {
        return (string) config('registration.mode', 'invite') === 'public' ? 'public' : 'invite';
    }

    public function isPublic(): bool
    {
        return $this->mode() === 'public';
    }

    public function findInvitation(?string $token): ?RegistrationInvitation
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        return RegistrationInvitation::query()
            ->where('token_hash', RegistrationInvitation::hashToken($token))
            ->first();
    }

    /** 公开注册：落待验证申请并发送验证邮件。 */
    public function startPublicSignup(array $data, Request $request): RegistrationSignup
    {
        $email = $this->normalizeEmail((string) ($data['email'] ?? ''));

        $this->hit("registration:ip:".$request->ip(), (int) config('registration.ip_limit_per_hour', 5), 3600);
        $this->hit("registration:email:".$email, (int) config('registration.email_limit_per_day', 3), 86400);

        if (! $this->turnstile->verify($data['turnstile_token'] ?? null, $request->ip())) {
            throw new RegistrationException(
                RegistrationException::TURNSTILE_FAILED,
                __('registration.errors.turnstile'),
            );
        }

        $this->assertEmailDomainAllowed($email);
        $this->assertEmailAvailable($email);

        $token = Str::random(64);
        $existing = RegistrationSignup::query()->where('email', $email)->first();

        $signup = RegistrationSignup::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => trim((string) $data['name']),
                'tenant_name' => trim((string) $data['tenant_name']),
                'password' => (string) $data['password'],
                'verification_token_hash' => RegistrationSignup::hashToken($token),
                'sent_count' => $existing !== null ? (int) $existing->sent_count + 1 : 1,
                'last_sent_at' => now(),
                'expires_at' => now()->addMinutes(max(1, (int) config('registration.verification_ttl_minutes', 60))),
                'ip_address' => $request->ip(),
                'locale' => app()->getLocale(),
            ],
        );

        Mail::to($email)->send(new RegistrationVerificationMail($signup, $token));

        return $signup;
    }

    /**
     * 重发验证邮件：对不存在的邮箱保持静默，避免账号枚举。
     */
    public function resend(string $email, Request $request): void
    {
        $email = $this->normalizeEmail($email);

        $this->hit("registration:ip:".$request->ip(), (int) config('registration.ip_limit_per_hour', 5), 3600);
        $this->hit("registration:email:".$email, (int) config('registration.email_limit_per_day', 3), 86400);

        $signup = RegistrationSignup::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->first();

        if ($signup === null || Admin::query()->where('email', $email)->exists()) {
            return;
        }

        $token = Str::random(64);
        $signup->forceFill([
            'verification_token_hash' => RegistrationSignup::hashToken($token),
            'sent_count' => (int) $signup->sent_count + 1,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes(max(1, (int) config('registration.verification_ttl_minutes', 60))),
        ])->save();

        Mail::to($email)->send(new RegistrationVerificationMail($signup, $token));
    }

    /** 公开注册：验证邮箱并创建租户。 */
    public function verify(string $token): Admin
    {
        $signup = RegistrationSignup::query()
            ->where('verification_token_hash', RegistrationSignup::hashToken($token))
            ->first();

        if ($signup === null || $signup->consumed_at !== null) {
            throw new RegistrationException(RegistrationException::INVALID, __('registration.errors.invalid_link'));
        }

        if ($signup->expires_at === null || $signup->expires_at->isPast()) {
            throw new RegistrationException(RegistrationException::EXPIRED, __('registration.errors.expired_link'));
        }

        return DB::transaction(function () use ($signup): Admin {
            $result = $this->provision([
                'email' => (string) $signup->email,
                'name' => (string) $signup->name,
                'tenant_name' => (string) $signup->tenant_name,
                'password' => (string) $signup->password,
            ]);

            $signup->forceFill([
                'consumed_at' => now(),
                'verification_token_hash' => RegistrationSignup::hashToken(Str::random(64)),
            ])->save();

            return $result['admin'];
        });
    }

    /** 邀请制：邀请链接即校验，直接创建租户。 */
    public function redeemInvitation(string $token, array $data): Admin
    {
        $invitation = $this->findInvitation($token);

        if ($invitation === null) {
            throw new RegistrationException(RegistrationException::INVALID, __('registration.errors.invalid_invitation'));
        }

        if ($invitation->used_at !== null) {
            throw new RegistrationException(RegistrationException::USED, __('registration.errors.used_invitation'));
        }

        if (! $invitation->isUsable()) {
            throw new RegistrationException(RegistrationException::EXPIRED, __('registration.errors.expired_invitation'));
        }

        $email = $this->normalizeEmail((string) ($invitation->email ?: ($data['email'] ?? '')));
        if ($email === '') {
            throw new RegistrationException(RegistrationException::INVALID, __('registration.errors.email_required'));
        }

        return DB::transaction(function () use ($invitation, $data, $email): Admin {
            $result = $this->provision([
                'email' => $email,
                'name' => (string) $data['name'],
                'tenant_name' => (string) ($invitation->tenant_name ?: $data['tenant_name']),
                'password' => (string) $data['password'],
            ]);

            $invitation->forceFill([
                'used_at' => now(),
                'used_tenant_id' => $result['tenant']->id,
            ])->save();

            return $result['admin'];
        });
    }

    /**
     * @return array{0: RegistrationInvitation, 1: string} [邀请记录, 明文 token（仅此处返回一次）]
     */
    public function createInvitation(array $data, int $adminId): array
    {
        $plain = RegistrationInvitation::newToken();

        $invitation = RegistrationInvitation::query()->create([
            'token_hash' => RegistrationInvitation::hashToken($plain),
            'email' => trim((string) ($data['email'] ?? '')) !== '' ? $this->normalizeEmail((string) $data['email']) : null,
            'tenant_name' => trim((string) ($data['tenant_name'] ?? '')) !== '' ? trim((string) $data['tenant_name']) : null,
            'note' => trim((string) ($data['note'] ?? '')) !== '' ? trim((string) $data['note']) : null,
            'created_by_admin_id' => $adminId,
            'expires_at' => now()->addDays(max(1, (int) config('registration.invitation_ttl_days', 7))),
        ]);

        return [$invitation, $plain];
    }

    /**
     * 两种模式共用的租户创建链路：租户 + 管理员 + owner 成员关系，同一事务。
     *
     * @return array{admin: Admin, tenant: Tenant}
     */
    private function provision(array $data): array
    {
        $email = $this->normalizeEmail((string) $data['email']);

        if ($email === '' || Admin::query()->where('email', $email)->exists()) {
            throw new RegistrationException(RegistrationException::EMAIL_TAKEN, __('registration.errors.email_taken'));
        }

        $tenant = Tenant::query()->create([
            'name' => trim((string) $data['tenant_name']),
            'slug' => $this->uniqueTenantSlug((string) $data['tenant_name']),
            'status' => 'active',
            'plan_code' => (string) config('billing.default_plan', 'free'),
        ]);

        $admin = Admin::query()->create([
            'username' => $this->uniqueUsername($email),
            'display_name' => trim((string) $data['name']),
            'email' => $email,
            'password' => (string) $data['password'],
            'role' => 'admin',
            'status' => 'active',
        ]);

        TenantMembership::query()->create([
            'tenant_id' => $tenant->id,
            'admin_id' => $admin->id,
            'role' => TenantMembership::ROLE_OWNER,
        ]);

        return ['admin' => $admin->refresh(), 'tenant' => $tenant];
    }

    private function assertEmailAvailable(string $email): void
    {
        if (Admin::query()->where('email', $email)->exists()) {
            throw new RegistrationException(RegistrationException::EMAIL_TAKEN, __('registration.errors.email_taken'));
        }
    }

    private function assertEmailDomainAllowed(string $email): void
    {
        $domain = strtolower((string) Str::after($email, '@'));
        $blocked = array_map(
            static fn ($item): string => strtolower(trim((string) $item)),
            (array) config('registration.disposable_domains', []),
        );

        if ($domain === '' || in_array($domain, $blocked, true)) {
            throw new RegistrationException(
                RegistrationException::DISPOSABLE_EMAIL,
                __('registration.errors.disposable_email'),
            );
        }
    }

    private function hit(string $key, int $max, int $decaySeconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new RegistrationException(RegistrationException::RATE_LIMITED, __('registration.errors.rate_limited'));
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function uniqueUsername(string $email): string
    {
        $base = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) Str::before($email, '@'))) ?? '';
        $base = mb_substr(trim($base, '_'), 0, 40);
        if ($base === '') {
            $base = 'user';
        }

        $candidate = $base;
        $suffix = 1;
        while (Admin::query()->where('username', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'_'.$suffix;
        }

        return $candidate;
    }

    private function uniqueTenantSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'tenant', 70, '');
        $candidate = $base;
        $suffix = 1;
        while (Tenant::query()->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }
}
