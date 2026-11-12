@extends('registration.layout')

@section('title', __('registration.register.title'))
@section('mode', $mode === 'public' ? __('registration.mode.public') : __('registration.mode.invite'))

@section('content')
    <h1>{{ __('registration.register.heading') }}</h1>
    <p class="lead">
        {{ $mode === 'public' ? __('registration.register.public_lead') : __('registration.register.invite_lead') }}
    </p>

    @if ($errors->any())
        <div class="alert alert--error" role="alert">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @if ($mode === 'invite' && (! $invitation || ! $invitation->isUsable()))
        <div class="alert alert--error" role="alert">
            {{ $invitation && $invitation->used_at !== null
                ? __('registration.errors.used_invitation')
                : __('registration.errors.invalid_invitation') }}
        </div>
        <div class="actions">
            <a class="button button--ghost" href="{{ route('admin.login') }}">{{ __('registration.register.go_login') }}</a>
        </div>
    @else
        <form method="POST" action="{{ route('register.store') }}">
            @csrf
            @if ($mode === 'invite')
                <input type="hidden" name="invite" value="{{ $inviteToken }}">
            @endif
            <div class="field">
                <label for="name">{{ __('registration.register.fields.name') }}</label>
                <input type="text" id="name" name="name" required maxlength="100" value="{{ old('name') }}" autocomplete="name">
            </div>
            <div class="field">
                <label for="tenant_name">{{ __('registration.register.fields.tenant_name') }}</label>
                <input type="text" id="tenant_name" name="tenant_name" required maxlength="120"
                       value="{{ old('tenant_name', $invitation->tenant_name ?? '') }}"
                       @if ($mode === 'invite' && ! empty($invitation?->tenant_name)) readonly @endif
                       autocomplete="organization">
                <div class="hint">{{ __('registration.register.fields.tenant_name_hint') }}</div>
            </div>
            <div class="field">
                <label for="email">{{ __('registration.register.fields.email') }}</label>
                <input type="email" id="email" name="email" required maxlength="100"
                       value="{{ old('email', $invitation->email ?? '') }}"
                       @if ($mode === 'invite' && ! empty($invitation?->email)) readonly @endif
                       autocomplete="email">
            </div>
            <div class="field">
                <label for="password">{{ __('registration.register.fields.password') }}</label>
                <input type="password" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password">
                <div class="hint">{{ __('registration.register.fields.password_hint') }}</div>
            </div>
            <div class="field">
                <label for="password_confirmation">{{ __('registration.register.fields.password_confirmation') }}</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password">
            </div>

            @if ($mode === 'public' && $turnstileEnabled && $turnstileSiteKey)
                <input type="hidden" name="turnstile_token" id="turnstile_token">
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-callback="geoflowTurnstileCallback"></div>
            @endif

            <div class="actions">
                <button type="submit">{{ __('registration.register.submit') }}</button>
            </div>
        </form>
        <div class="links">
            <a href="{{ route('admin.login') }}">{{ __('registration.register.have_account') }}</a>
        </div>
    @endif
@endsection

@if ($mode === 'public' && $turnstileEnabled && $turnstileSiteKey)
    @push('scripts')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        <script>
            function geoflowTurnstileCallback(token) {
                const input = document.getElementById('turnstile_token');
                if (input) input.value = token;
            }
        </script>
    @endpush
@endif
