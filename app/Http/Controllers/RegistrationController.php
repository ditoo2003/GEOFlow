<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Services\Registration\RegistrationException;
use App\Services\Registration\RegistrationService;
use App\Services\Registration\TurnstileVerifier;
use App\Support\Branding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * 自助注册入口（邀请制 / 公开注册）。
 *
 * 两种模式共用同一个表单与同一条租户创建链路，仅校验前置不同。
 */
final class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrations,
        private readonly TurnstileVerifier $turnstile,
    ) {}

    public function show(Request $request)
    {
        if ($this->registrations->isPublic()) {
            return view('registration.register', [
                'mode' => 'public',
                'brand' => Branding::all(),
                'turnstileEnabled' => $this->turnstile->enabled(),
                'turnstileSiteKey' => $this->turnstile->siteKey(),
                'invitation' => null,
                'inviteToken' => '',
            ]);
        }

        $inviteToken = trim((string) $request->query('invite', ''));

        return view('registration.register', [
            'mode' => 'invite',
            'brand' => Branding::all(),
            'turnstileEnabled' => false,
            'turnstileSiteKey' => null,
            'invitation' => $this->registrations->findInvitation($inviteToken),
            'inviteToken' => $inviteToken,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'tenant_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'invite' => ['nullable', 'string', 'max:191'],
            'turnstile_token' => ['nullable', 'string', 'max:4096'],
        ], $this->validationMessages());

        try {
            if ($this->registrations->isPublic()) {
                $this->registrations->startPublicSignup($data, $request);

                return response()->view('registration.result', [
                    'brand' => Branding::all(),
                    'title' => __('registration.result.check_email_title'),
                    'message' => __('registration.result.check_email', ['email' => strtolower($data['email'])]),
                    'showResend' => true,
                    'email' => strtolower($data['email']),
                ]);
            }

            $admin = $this->registrations->redeemInvitation((string) ($data['invite'] ?? ''), $data);
        } catch (RegistrationException $exception) {
            if ($exception->reason === RegistrationException::RATE_LIMITED) {
                abort(429, $exception->getMessage());
            }

            return back()->withInput($request->only('name', 'tenant_name', 'email'))->withErrors([
                'registration' => $exception->getMessage(),
            ]);
        }

        return $this->login($request, $admin);
    }

    public function verify(Request $request, string $token)
    {
        try {
            $admin = $this->registrations->verify($token);
        } catch (RegistrationException $exception) {
            return response()->view('registration.result', [
                'brand' => Branding::all(),
                'title' => __('registration.result.verify_failed_title'),
                'message' => $exception->getMessage(),
                'showResend' => false,
                'email' => null,
            ]);
        }

        return $this->login($request, $admin);
    }

    public function resend(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:100'],
        ], $this->validationMessages());

        try {
            $this->registrations->resend((string) $data['email'], $request);
        } catch (RegistrationException $exception) {
            if ($exception->reason === RegistrationException::RATE_LIMITED) {
                abort(429, $exception->getMessage());
            }

            return back()->withErrors(['email' => $exception->getMessage()]);
        }

        return response()->view('registration.result', [
            'brand' => Branding::all(),
            'title' => __('registration.result.resend_title'),
            'message' => __('registration.result.resend_sent'),
            'showResend' => false,
            'email' => null,
        ]);
    }

    private function login(Request $request, Admin $admin)
    {
        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard');
    }

    /** @return array<string, string> */
    private function validationMessages(): array
    {
        return [
            'name.required' => __('registration.errors.name_required'),
            'tenant_name.required' => __('registration.errors.tenant_required'),
            'email.required' => __('registration.errors.email_required'),
            'email.email' => __('registration.errors.email_invalid'),
            'password.required' => __('registration.errors.password_required'),
            'password.min' => __('registration.errors.password_too_short'),
            'password.confirmed' => __('registration.errors.password_mismatch'),
        ];
    }
}
