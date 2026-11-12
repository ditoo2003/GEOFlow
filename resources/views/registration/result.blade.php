@extends('registration.layout')

@section('title', $title)
@section('mode', __('registration.mode.public'))

@section('content')
    <h1>{{ $title }}</h1>
    <p class="lead">{{ $message }}</p>

    @if (! empty($showResend) && ! empty($email))
        <form method="POST" action="{{ route('register.resend') }}">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">
            <div class="actions">
                <button type="submit">{{ __('registration.result.resend_action') }}</button>
            </div>
        </form>
    @endif

    <div class="actions">
        <a class="button button--ghost" href="{{ route('admin.login') }}">{{ __('registration.register.go_login') }}</a>
    </div>
@endsection
