<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\AdminActivityLogger;
use App\Support\AdminTenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * 超管后台租户上下文切换：选择具体租户或回到平台（全部租户）视图。
 */
class AdminTenantContextController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = isset($data['tenant_id']) ? (int) $data['tenant_id'] : null;

        $tenant = $tenantId === null ? null : Tenant::query()->whereKey($tenantId)->first();
        if ($tenantId !== null && $tenant === null) {
            throw ValidationException::withMessages(['tenant_id' => '所选租户不存在，请重新选择。']);
        }

        AdminTenantContext::select($tenantId);

        $admin = Auth::guard('admin')->user();
        if ($admin !== null) {
            AdminActivityLogger::logFromRequest($request, $admin, 'tenant-context:switch', [
                'tenant_id' => $tenantId,
            ]);
        }

        return back()->with(
            'status',
            $tenant === null ? '已切换到平台（全部租户）视图。' : '已切换到租户：'.$tenant->name
        );
    }
}
