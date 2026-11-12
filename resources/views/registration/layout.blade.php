@php
    $brand = is_array($brand ?? null) ? $brand : \App\Support\Branding::all();
    $brandColor = $brand['primary_color'] ?: '#2563eb';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') · {{ $brand['name'] }}</title>
    <style>
        :root { --brand: {{ $brandColor }}; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            background: #f5f6f8;
            color: #111827;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .card {
            width: 100%;
            max-width: 480px;
            padding: 32px;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
        }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
        .brand__mark {
            display: flex; align-items: center; justify-content: center;
            width: 44px; height: 44px; border-radius: 12px;
            background: var(--brand); color: #fff; font-weight: 700; overflow: hidden;
        }
        .brand__mark img { width: 100%; height: 100%; object-fit: contain; background: #fff; }
        .brand__name { font-size: 18px; font-weight: 700; }
        .brand__mode { font-size: 12px; color: #6b7280; margin-top: 2px; }
        h1 { margin: 0 0 8px; font-size: 24px; }
        .lead { margin: 0 0 24px; color: #4b5563; font-size: 14px; line-height: 1.6; }
        .field { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #374151; }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%; padding: 11px 12px; border: 1px solid #d1d5db; border-radius: 10px;
            font-size: 14px; background: #fff;
        }
        input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 20%, transparent); }
        .hint { margin-top: 6px; font-size: 12px; color: #6b7280; }
        button, .button {
            display: inline-flex; align-items: center; justify-content: center;
            width: 100%; padding: 12px 16px; border: 0; border-radius: 10px;
            background: var(--brand); color: #fff; font-size: 15px; font-weight: 600;
            cursor: pointer; text-decoration: none;
        }
        .button--ghost { background: #f3f4f6; color: #374151; }
        .alert { margin-bottom: 16px; padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.6; }
        .alert--error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }
        .alert--ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #047857; }
        .actions { margin-top: 20px; display: grid; gap: 10px; }
        .footer { margin-top: 24px; font-size: 12px; color: #6b7280; text-align: center; line-height: 1.8; }
        .footer a { color: #4b5563; text-decoration: none; border-bottom: 1px solid #d1d5db; }
        .footer a:hover { color: var(--brand); border-color: var(--brand); }
        .links { margin-top: 20px; text-align: center; font-size: 13px; }
        .links a { color: var(--brand); text-decoration: none; }
    </style>
</head>
<body>
<div class="card" data-registration-card>
    <div class="brand">
        <div class="brand__mark">
            @if (! empty($brand['logo_url']))
                <img src="{{ $brand['logo_url'] }}" alt="{{ $brand['name'] }}">
            @else
                {{ mb_substr($brand['name'], 0, 1) }}
            @endif
        </div>
        <div>
            <div class="brand__name">{{ $brand['name'] }}</div>
            <div class="brand__mode">@yield('mode')</div>
        </div>
    </div>
    @yield('content')
</div>
<div class="footer">
    <a href="{{ route('source') }}">{{ __('registration.footer.source') }}</a>
    · {{ __('registration.footer.license') }}: {{ $brand['license'] }}
    <br>
    © {{ date('Y') }} {{ $brand['copyright'] }}
</div>
@stack('scripts')
</body>
</html>
