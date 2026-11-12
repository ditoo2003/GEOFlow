@php
    $brand = is_array($brand ?? null) ? $brand : \App\Support\Branding::all();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('registration.mail.subject', ['brand' => $brand['name']]) }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f6f8;font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">
    <tr>
        <td style="padding:28px 28px 8px;">
            <div style="font-size:15px;font-weight:700;">{{ $brand['name'] }}</div>
        </td>
    </tr>
    <tr>
        <td style="padding:8px 28px 0;">
            <h1 style="margin:0 0 12px;font-size:20px;">{{ __('registration.mail.heading') }}</h1>
            <p style="margin:0 0 12px;font-size:14px;line-height:1.7;color:#374151;">
                {{ __('registration.mail.greeting', ['name' => $name]) }}
            </p>
            <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#374151;">
                {{ __('registration.mail.intro', ['tenant' => $tenantName]) }}
            </p>
            <p style="margin:0 0 24px;">
                <a href="{{ $verificationUrl }}" style="display:inline-block;padding:12px 20px;border-radius:10px;background:{{ $brand['primary_color'] }};color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">
                    {{ __('registration.mail.action') }}
                </a>
            </p>
            <p style="margin:0 0 12px;font-size:13px;line-height:1.7;color:#6b7280;">
                {{ __('registration.mail.expiry', ['minutes' => $ttlMinutes]) }}
            </p>
            <p style="margin:0 0 12px;font-size:12px;line-height:1.7;color:#9ca3af;word-break:break-all;">
                {{ $verificationUrl }}
            </p>
            <p style="margin:0 0 8px;font-size:12px;line-height:1.7;color:#9ca3af;">
                {{ __('registration.mail.ignore') }}
            </p>
        </td>
    </tr>
    <tr>
        <td style="padding:16px 28px 28px;border-top:1px solid #f3f4f6;">
            <p style="margin:0;font-size:11px;line-height:1.8;color:#9ca3af;">
                {{ $brand['name'] }} · {{ $brand['license'] }} · © {{ date('Y') }} {{ $brand['copyright'] }}
                <br>
                <a href="{{ $brand['source_url'] }}" style="color:#6b7280;">{{ $brand['source_url'] }}</a>
            </p>
        </td>
    </tr>
</table>
</body>
</html>
