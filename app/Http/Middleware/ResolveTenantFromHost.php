<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 全局中间件：按请求 hostname 解析租户（前台站点），未命中站点时落在默认租户。
 *
 * 后台会话与 API Token 的显式绑定由各鉴权中间件覆盖此默认值。
 */
class ResolveTenantFromHost
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        // 系统上下文可能来自同进程内的上一次超管请求或队列任务；
        // 前台请求必须重新按 hostname 落租户，不能沿用系统态。
        if (! $context->isResolved() || $context->isSystem()) {
            $resolver = app(TenantResolver::class);
            $hostname = (string) $request->attributes->get('normalized_host', '');

            $tenantId = $resolver->tenantIdForHostname($hostname) ?? $resolver->defaultTenantId();
            $context->useTenant($tenantId);
        }

        return $next($request);
    }
}
