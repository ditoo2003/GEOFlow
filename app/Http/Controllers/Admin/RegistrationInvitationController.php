<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistrationInvitation;
use App\Services\Registration\RegistrationService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 邀请制注册：管理员生成一次性邀请链接（仅超管），注册端不要求邮箱验证。
 */
class RegistrationInvitationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrations,
    ) {}

    public function index(): View
    {
        return view('admin.registration-invitations.index', [
            'pageTitle' => __('platform_admin.invitations.title'),
            'activeMenu' => 'registration_invitations',
            'mode' => $this->registrations->mode(),
            'invitations' => RegistrationInvitation::query()->orderByDesc('id')->limit(100)->get(),
            'generatedUrl' => (string) session('invitation_url', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:100'],
            'tenant_name' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        [$invitation, $plainToken] = $this->registrations->createInvitation($data, (int) $request->user('admin')->getKey());

        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'registration-invitation:create', [
            'invitation_id' => (int) $invitation->id,
            'email' => $invitation->email,
        ]);

        return back()
            ->with('status', __('platform_admin.invitations.created'))
            ->with('invitation_url', route('register', ['invite' => $plainToken]));
    }

    public function destroy(Request $request, int $invitation): RedirectResponse
    {
        $record = RegistrationInvitation::query()->whereKey($invitation)->firstOrFail();
        $record->delete();

        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'registration-invitation:delete', [
            'invitation_id' => $invitation,
        ]);

        return back()->with('status', __('platform_admin.invitations.deleted'));
    }
}
