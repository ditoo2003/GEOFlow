@extends('admin.layouts.app')

@section('content')
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">{{ __('platform_admin.branding.title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('platform_admin.branding.subtitle') }}</p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 rounded-xl border border-gray-200 bg-white p-6">
            <form method="POST" action="{{ route('admin.branding.update') }}" class="grid gap-4">
                @csrf
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">{{ __('platform_admin.branding.fields.name') }}</label>
                    <input type="text" id="name" name="name" required maxlength="60" value="{{ old('name', $brand['name']) }}"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <p class="mt-1 text-xs text-gray-400">{{ __('platform_admin.branding.fields.name_hint') }}</p>
                </div>
                <div>
                    <label for="logo_url" class="block text-sm font-medium text-gray-700">{{ __('platform_admin.branding.fields.logo_url') }}</label>
                    <input type="url" id="logo_url" name="logo_url" maxlength="500" value="{{ old('logo_url', $brand['logo_url']) }}"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                           placeholder="https://example.com/logo.png">
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="primary_color" class="block text-sm font-medium text-gray-700">{{ __('platform_admin.branding.fields.primary_color') }}</label>
                        <input type="text" id="primary_color" name="primary_color" required maxlength="7" value="{{ old('primary_color', $brand['primary_color']) }}"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm" placeholder="#2563eb">
                    </div>
                    <div>
                        <label for="support_email" class="block text-sm font-medium text-gray-700">{{ __('platform_admin.branding.fields.support_email') }}</label>
                        <input type="email" id="support_email" name="support_email" maxlength="100" value="{{ old('support_email', $brand['support_email']) }}"
                               class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <div>
                    <label for="source_url" class="block text-sm font-medium text-gray-700">{{ __('platform_admin.branding.fields.source_url') }}</label>
                    <input type="url" id="source_url" name="source_url" required maxlength="500" value="{{ old('source_url', $brand['source_url']) }}"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <p class="mt-1 text-xs text-gray-500">{{ __('platform_admin.branding.fields.source_url_hint') }}</p>
                </div>
                <div>
                    <label for="copyright" class="block text-sm font-medium text-gray-700">{{ __('platform_admin.branding.fields.copyright') }}</label>
                    <input type="text" id="copyright" name="copyright" required maxlength="200" value="{{ old('copyright', $brand['copyright']) }}"
                           class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">{{ __('platform_admin.branding.fields.license') }}</label>
                    <input type="text" value="{{ $brand['license'] }}" readonly
                           class="mt-1 block w-full rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-600">
                    <p class="mt-1 text-xs text-gray-400">{{ __('platform_admin.branding.fields.license_hint') }}</p>
                </div>
                <div>
                    <button type="submit" class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        {{ __('platform_admin.branding.submit') }}
                    </button>
                </div>
            </form>
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-gray-900">{{ __('platform_admin.branding.preview_title') }}</h2>
                <div class="mt-4 flex items-center gap-3">
                    @if (! empty($brand['logo_url']))
                        <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}" class="h-10 w-10 rounded-lg border border-gray-200 object-contain">
                    @else
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg text-sm font-bold text-white" style="background: {{ $brand['primary_color'] }}">
                            {{ mb_substr($brand['name'], 0, 1) }}
                        </div>
                    @endif
                    <div>
                        <div class="text-sm font-semibold text-gray-900">{{ $brand['name'] }}</div>
                        <div class="text-xs text-gray-500">{{ $brand['license'] }}</div>
                    </div>
                </div>
                <div class="mt-4 h-2 rounded-full" style="background: {{ $brand['primary_color'] }}"></div>
            </div>

            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-xs leading-6 text-amber-900">
                <div class="text-sm font-semibold">{{ __('platform_admin.branding.agpl_title') }}</div>
                <p class="mt-2">{{ __('platform_admin.branding.agpl_notice') }}</p>
                <a href="{{ route('source') }}" class="mt-2 inline-block font-medium underline">{{ __('registration.footer.source') }}</a>
            </div>

            <div class="rounded-xl border border-gray-200 bg-gray-50 p-5 text-xs leading-6 text-gray-600">
                {{ __('platform_admin.branding.scope_notice') }}
            </div>
        </div>
    </div>
@endsection
