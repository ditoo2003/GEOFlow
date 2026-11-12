@extends('admin.layouts.app')

@section('content')
    <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ __('platform_admin.invitations.title') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('platform_admin.invitations.subtitle') }}</p>
        </div>
        <span class="inline-flex w-fit items-center rounded-full border px-3 py-1 text-xs font-medium {{ $mode === 'invite' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' }}">
            {{ __('platform_admin.invitations.current_mode') }}: {{ $mode === 'invite' ? __('registration.mode.invite') : __('registration.mode.public') }}
        </span>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    @if (! empty($generatedUrl))
        <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50 p-4" data-invitation-generated>
            <div class="text-sm font-semibold text-blue-900">{{ __('platform_admin.invitations.generated_title') }}</div>
            <p class="mt-1 text-xs text-blue-700">{{ __('platform_admin.invitations.generated_hint') }}</p>
            <div class="mt-3 break-all rounded-lg border border-blue-200 bg-white px-3 py-2 font-mono text-xs text-gray-800" data-invitation-url>{{ $generatedUrl }}</div>
        </div>
    @endif

    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-gray-900">{{ __('platform_admin.invitations.create_title') }}</h2>
        <form method="POST" action="{{ route('admin.registration-invitations.store') }}" class="mt-4 grid gap-4 sm:grid-cols-3">
            @csrf
            <div>
                <label for="email" class="block text-xs font-medium text-gray-600">{{ __('platform_admin.invitations.fields.email') }}</label>
                <input type="email" id="email" name="email" maxlength="100" value="{{ old('email') }}"
                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-gray-400">{{ __('platform_admin.invitations.fields.email_hint') }}</p>
            </div>
            <div>
                <label for="tenant_name" class="block text-xs font-medium text-gray-600">{{ __('platform_admin.invitations.fields.tenant_name') }}</label>
                <input type="text" id="tenant_name" name="tenant_name" maxlength="120" value="{{ old('tenant_name') }}"
                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="note" class="block text-xs font-medium text-gray-600">{{ __('platform_admin.invitations.fields.note') }}</label>
                <input type="text" id="note" name="note" maxlength="255" value="{{ old('note') }}"
                       class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div class="sm:col-span-3">
                <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    {{ __('platform_admin.invitations.create_submit') }}
                </button>
            </div>
        </form>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3 text-left">{{ __('platform_admin.invitations.columns.email') }}</th>
                <th class="px-4 py-3 text-left">{{ __('platform_admin.invitations.columns.tenant') }}</th>
                <th class="px-4 py-3 text-left">{{ __('platform_admin.invitations.columns.note') }}</th>
                <th class="px-4 py-3 text-left">{{ __('platform_admin.invitations.columns.status') }}</th>
                <th class="px-4 py-3 text-left">{{ __('platform_admin.invitations.columns.expires') }}</th>
                <th class="px-4 py-3 text-right">{{ __('platform_admin.invitations.columns.actions') }}</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
            @forelse ($invitations as $invitation)
                @php($status = $invitation->status())
                <tr data-invitation-row>
                    <td class="px-4 py-3 text-gray-900">{{ $invitation->email ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $invitation->tenant_name ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-500">{{ $invitation->note ?: '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                            {{ $status === 'active' ? 'bg-emerald-50 text-emerald-700' : ($status === 'used' ? 'bg-gray-100 text-gray-500' : 'bg-red-50 text-red-600') }}">
                            {{ __('platform_admin.invitations.status.'.$status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-500">{{ $invitation->expires_at?->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3 text-right">
                        <form method="POST" action="{{ route('admin.registration-invitations.destroy', ['invitation' => $invitation->id]) }}" onsubmit="return confirm('{{ __('platform_admin.invitations.delete_confirm') }}')">
                            @csrf
                            <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700">
                                {{ __('platform_admin.invitations.delete') }}
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-400">{{ __('platform_admin.invitations.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
