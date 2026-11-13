<?php

namespace App\Services\Registration;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile 校验（公开注册可选，默认关闭）。
 *
 * 未启用时始终放行；启用但未配置密钥时拒绝（fail-closed），并记录错误日志。
 */
final class TurnstileVerifier
{
    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('registration.turnstile.enabled', false);
    }

    public function siteKey(): ?string
    {
        $key = trim((string) config('registration.turnstile.site_key', ''));

        return $key !== '' ? $key : null;
    }

    public function verify(?string $token, ?string $ip = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $secret = trim((string) config('registration.turnstile.secret_key', ''));
        if ($secret === '') {
            Log::error('registration.turnstile_missing_secret');

            return false;
        }

        if (! is_string($token) || trim($token) === '') {
            return false;
        }

        try {
            $response = $this->safeHttp->post(
                $this->http->asForm()->timeout(5),
                (string) config('registration.turnstile.verify_url'),
                [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ],
                (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024),
            );

            return $response->ok() && (bool) $response->json('success');
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
