<?php

namespace App\Http\Middleware;

use App\Services\Site\HostedSiteResolver;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 全局中间件：按请求 hostname 解析租户（前台站点）。
 *
 * 只有两种显式归属：主站域名（`GEOFLOW_PRIMARY_HOSTS` / `APP_URL` / `SITE_URL`）落默认租户；
 * 已登记的托管站点域名落站点所属租户。其余未绑定域名保持未解析，由 {@see ResolveCurrentSite}
 * 返回 404，任何租户查询都会 fail-closed，不再隐式回落到默认租户。
 *
 * 后台会话与 API Token 的显式绑定由各鉴权中间件覆盖此默认值。
 */
class ResolveTenantFromHost
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly HostedSiteResolver $siteResolver,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 系统上下文可能来自同进程内的上一次超管请求或队列任务；
        // 前台请求必须重新按 hostname 落租户，不能沿用系统态。
        if (! $this->context->isResolved() || $this->context->isSystem()) {
            $hostname = (string) $request->attributes->get('normalized_host', '');

            if ($this->siteResolver->isPrimaryHost($hostname)) {
                $this->context->useTenant($this->resolver->defaultTenantId());
            } else {
                $tenantId = $this->resolver->tenantIdForHostname($hostname);

                if ($tenantId !== null) {
                    $this->context->useTenant($tenantId);
                } else {
                    $this->context->clear();
                }
            }
        }

        return $next($request);
    }
}
