<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Services\Api\ApiTokenService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(
        private ApiTokenService $tokenService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');
        if (! is_string($authorization) || $authorization === '') {
            throw new ApiException('unauthorized', '缺少 Authorization 头', 401);
        }

        if (! preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('unauthorized', 'Authorization 格式无效', 401);
        }

        $tokenValue = trim($matches[1]);
        if ($tokenValue === '') {
            throw new ApiException('unauthorized', 'Token 不能为空', 401);
        }

        $token = $this->tokenService->getActiveTokenByPlaintext($tokenValue);
        if (! $token) {
            throw new ApiException('unauthorized', 'Token 无效或已过期', 401);
        }

        $this->tokenService->touchToken((int) $token['id']);
        $auditAdminId = (int) ($token['created_by_admin_id'] ?? 0);
        if ($auditAdminId <= 0) {
            throw new ApiException('unauthorized', 'Token 所属管理员无效', 401);
        }

        $request->attributes->set('api_auth', new ApiAuthContext($token, $auditAdminId));

        $admin = Admin::query()->find($auditAdminId);
        if ($admin instanceof Admin) {
            if (! app(TenantResolver::class)->applyForAdmin($admin)) {
                throw new ApiException('forbidden', '当前账号未绑定任何租户', 403);
            }
        } else {
            $tenantId = app(TenantResolver::class)->singleTenantFallbackId();
            if ($tenantId === null) {
                throw new ApiException('forbidden', '无法确定 Token 所属租户', 403);
            }

            app(TenantContext::class)->useTenant($tenantId);
        }

        return $next($request);
    }
}
