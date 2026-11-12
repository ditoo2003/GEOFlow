<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 当前请求/任务的租户上下文（fail-closed）。
 *
 * 三种状态：
 * - 已解析到租户：所有带租户作用域的模型查询按 `tenant_id` 过滤；
 * - 平台/系统上下文（{@see useSystem()}）：跨租户，仅用于超管运维、迁移、更新器与队列根上下文；
 * - 未解析：任何租户模型查询都会抛 {@see TenantContextMissingException}。
 *
 * PostgreSQL 下切换上下文会同步会话变量 `app.tenant_id`，供 RLS 策略使用。
 */
final class TenantContext
{
    public const SYSTEM = 'system';

    public const SESSION_VARIABLE = 'app.tenant_id';

    public const SESSION_BYPASS_VARIABLE = 'app.tenant_bypass';

    private ?int $tenantId = null;

    private bool $system = false;

    private bool $resolved = false;

    public function useTenant(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new TenantContextMissingException('租户 id 非法：'.$tenantId);
        }

        $this->tenantId = $tenantId;
        $this->system = false;
        $this->resolved = true;

        $this->applyToDatabase((string) $tenantId, false);
    }

    public function useSystem(): void
    {
        $this->tenantId = null;
        $this->system = true;
        $this->resolved = true;

        // 系统上下文用独立开关放行，租户变量保持空串，避免 RLS 里对非数字做 ::bigint 转换。
        $this->applyToDatabase('', true);
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->system = false;
        $this->resolved = false;

        $this->applyToDatabase('', false);
    }

    public function isResolved(): bool
    {
        return $this->resolved;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function tenantId(): ?int
    {
        return $this->resolved && ! $this->system ? $this->tenantId : null;
    }

    public function requireTenantId(): int
    {
        if (! $this->resolved) {
            throw new TenantContextMissingException('租户上下文未初始化');
        }

        if ($this->system || $this->tenantId === null) {
            throw new TenantContextMissingException('系统上下文不包含具体租户');
        }

        return $this->tenantId;
    }

    /**
     * 用于"必须有归属租户"的写入：系统上下文（超管/平台）落到默认租户。
     */
    public function tenantIdOrDefault(): int
    {
        if ($this->resolved && ! $this->system && $this->tenantId !== null) {
            return $this->tenantId;
        }

        return (int) config('tenancy.default_tenant_id', 1);
    }

    /**
     * 站点级写入的归属租户。
     *
     * 租户上下文直接返回自身；系统上下文（超管/平台）先按站点归属解析，
     * 单租户部署兜底到唯一租户；多租户且解析不到时抛出中文异常，
     * 不再隐式回落到默认租户（超管需先切换到目标租户）。
     */
    public function tenantIdForSite(?string $siteKey): int
    {
        if ($this->resolved && ! $this->system && $this->tenantId !== null) {
            return $this->tenantId;
        }

        $resolver = app(TenantResolver::class);

        return $resolver->tenantIdForSiteKey($siteKey)
            ?? $resolver->singleTenantFallbackId()
            ?? throw TenantContextMissingException::forSiteWrite($siteKey);
    }

    /**
     * 在指定租户上下文中执行，结束后恢复原上下文。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(int $tenantId, Closure $callback): mixed
    {
        $previous = $this->snapshot();
        $this->useTenant($tenantId);

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /**
     * 在系统上下文中执行，结束后恢复原上下文。
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsSystem(Closure $callback): mixed
    {
        $previous = $this->snapshot();
        $this->useSystem();

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    /** @return array{resolved: bool, system: bool, tenant_id: ?int} */
    private function snapshot(): array
    {
        return [
            'resolved' => $this->resolved,
            'system' => $this->system,
            'tenant_id' => $this->tenantId,
        ];
    }

    /** @param array{resolved: bool, system: bool, tenant_id: ?int} $state */
    private function restore(array $state): void
    {
        $this->resolved = $state['resolved'];
        $this->system = $state['system'];
        $this->tenantId = $state['tenant_id'];

        if (! $this->resolved) {
            $this->applyToDatabase('', false);
        } elseif ($this->system) {
            $this->applyToDatabase('', true);
        } else {
            $this->applyToDatabase((string) $this->tenantId, false);
        }
    }

    private function applyToDatabase(string $tenantId, bool $bypass): void
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'pgsql') {
                return;
            }

            $connection->statement(
                "select set_config('".self::SESSION_VARIABLE."', ?, false)",
                [$tenantId]
            );
            $connection->statement(
                "select set_config('".self::SESSION_BYPASS_VARIABLE."', ?, false)",
                [$bypass ? 'on' : 'off']
            );
        } catch (Throwable) {
            // 数据库尚未就绪（如迁移早期/配置缺失）时不影响上下文状态本身。
        }
    }
}
