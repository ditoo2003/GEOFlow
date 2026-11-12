<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Session;

/**
 * 超管后台的租户上下文选择（会话级）。
 *
 * 仅对 `super_admin` 生效；未选择时保持平台（系统）上下文，
 * 站点级写入会要求先选择具体租户。普通站点管理员不读取该值。
 */
final class AdminTenantContext
{
    public const SESSION_KEY = 'admin.selected_tenant_id';

    /** @return array<int, string> */
    public static function options(): array
    {
        return Tenant::query()->orderBy('id')->pluck('name', 'id')->all();
    }

    public static function selectedTenantId(): ?int
    {
        $tenantId = Session::get(self::SESSION_KEY);

        return is_numeric($tenantId) && (int) $tenantId > 0 ? (int) $tenantId : null;
    }

    public static function select(?int $tenantId): void
    {
        if ($tenantId === null) {
            self::forget();

            return;
        }

        Session::put(self::SESSION_KEY, $tenantId);
    }

    public static function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
