@extends('registration.layout')

@section('title', __('registration.source.title'))
@section('mode', __('registration.source.mode'))

@section('content')
    <h1>{{ __('registration.source.heading') }}</h1>
    <p class="lead">{{ __('registration.source.intro', ['name' => $brand['name']]) }}</p>

    <div class="alert alert--ok">
        <div><strong>{{ __('registration.source.url_label') }}</strong></div>
        <div style="margin-top:6px;word-break:break-all;">
            <a href="{{ $brand['source_url'] }}" rel="noopener noreferrer">{{ $brand['source_url'] }}</a>
        </div>
    </div>

    <div class="field">
        <label>{{ __('registration.source.license_label') }}</label>
        <input type="text" value="{{ $brand['license'] }}" readonly>
    </div>
    <div class="field">
        <label>{{ __('registration.source.copyright_label') }}</label>
        <input type="text" value="{{ $brand['copyright'] }}" readonly>
    </div>

    <p class="lead">{{ __('registration.source.notice') }}</p>

    <div class="actions">
        <a class="button" href="{{ $brand['source_url'] }}" rel="noopener noreferrer">{{ __('registration.source.download') }}</a>
        <a class="button button--ghost" href="{{ route('admin.login') }}">{{ __('registration.register.go_login') }}</a>
    </div>
@endsection
