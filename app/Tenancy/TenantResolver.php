<?php

namespace App\Tenancy;

use App\Models\Admin;
use App\Models\HostedSiteProfile;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\Site\HostedSiteResolver;
use App\Support\AdminTenantContext;

/**
 * 租户解析规则：后台会话 / API Token / 前台 hostname / 队列任务。
 *
 * 解析顺序统一为"显式绑定优先，其次单租户兜底；多租户且无法确定时失败"。
 */
final class TenantResolver
{
    /** @var array<int, int|null> */
    private array $adminCache = [];

    /**
     * 前台 hostname → 租户。解析站点本身发生在租户确定之前，必须在系统上下文执行。
     */
    public function tenantIdForHostname(string $hostname): ?int
    {
        if ($hostname === '') {
            return null;
        }

        $profile = app(TenantContext::class)->runAsSystem(
            static fn () => app(HostedSiteResolver::class)->findHostedProfile($hostname)
        );

        $tenantId = $profile?->getAttribute('tenant_id');

        return is_numeric($tenantId) ? (int) $tenantId : null;
    }

    /**
     * 管理员 → 租户。平台超管返回 null（由调用方切换为系统上下文）。
     */
    public function tenantIdForAdmin(Admin $admin): ?int
    {
        if ($admin->isSuperAdmin()) {
            return null;
        }

        $adminId = (int) $admin->getKey();
        if (array_key_exists($adminId, $this->adminCache)) {
            return $this->adminCache[$adminId];
        }

        $tenantId = TenantMembership::query()
            ->where('admin_id', $adminId)
            ->orderBy('id')
            ->value('tenant_id');

        return $this->adminCache[$adminId] = is_numeric($tenantId) ? (int) $tenantId : null;
    }

    /**
     * 仅当系统里确实只有一个租户时兜底到该租户；多租户下返回 null，由调用方拒绝请求。
     */
    public function singleTenantFallbackId(): ?int
    {
        if ($this->tenantCount() !== 1) {
            return null;
        }

        $id = Tenant::query()->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function tenantCount(): int
    {
        return Tenant::query()->count();
    }

    /**
     * 全部租户 ID（升序）。调度与 CLI 用它按租户迭代执行。
     *
     * @return list<int>
     */
    public function tenantIds(): array
    {
        return Tenant::query()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function tenantExists(int $tenantId): bool
    {
        return Tenant::query()->whereKey($tenantId)->exists();
    }

    public function defaultTenantId(): int
    {
        return (int) config('tenancy.default_tenant_id', 1);
    }

    /**
     * 站点 → 租户。`hosted:{id}` 取站点归属租户；本地主站（primary）没有站点记录，
     * 由调用方回落到默认租户。超管在系统上下文写入站点数据时用它确定归属。
     */
    public function tenantIdForSiteKey(?string $siteKey): ?int
    {
        if ($siteKey === null || preg_match('/^hosted:([1-9][0-9]*)$/D', $siteKey, $matches) !== 1) {
            return null;
        }

        $tenantId = app(TenantContext::class)->runAsSystem(
            static fn () => HostedSiteProfile::query()->whereKey((int) $matches[1])->value('tenant_id')
        );

        return is_numeric($tenantId) ? (int) $tenantId : null;
    }

    /**
     * 为后台请求确定并应用上下文。无法确定租户且不是超管时返回 false。
     *
     * 超管默认使用系统上下文（可浏览全部租户）；在后台显式选择租户后，
     * 后续请求按所选租户执行，站点级写入才会落到该租户。
     */
    public function applyForAdmin(Admin $admin): bool
    {
        $context = app(TenantContext::class);

        if ($admin->isSuperAdmin()) {
            $selected = AdminTenantContext::selectedTenantId();
            if ($selected !== null) {
                if (Tenant::query()->whereKey($selected)->exists()) {
                    $context->useTenant($selected);

                    return true;
                }

                AdminTenantContext::forget();
            }

            $context->useSystem();

            return true;
        }

        $tenantId = $this->tenantIdForAdmin($admin) ?? $this->singleTenantFallbackId();
        if ($tenantId === null) {
            return false;
        }

        $context->useTenant($tenantId);

        return true;
    }
}
