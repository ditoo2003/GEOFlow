<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Tenant;
use App\Models\TenantQuotaAlert;
use App\Models\TenantUsageEvent;
use App\Services\Billing\TenantPlanCatalog;
use App\Services\Billing\TenantQuotaReconciliationService;
use App\Services\Billing\TenantQuotaService;
use App\Support\AdminActivityLogger;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 用量与配额看板。
 *
 * - 普通管理员：只看本租户；
 * - 超管 + 已选租户：看该租户；
 * - 超管 + 系统上下文：全局按租户列表（系统上下文显式按 tenant_id 聚合）。
 * 单租户部署不渲染租户列/切换器。写接口仅超管可用。
 */
class TenantUsageController extends Controller
{
    public function __construct(
        private readonly TenantQuotaService $quota,
        private readonly TenantPlanCatalog $plans,
    ) {}

    public function index(Request $request): View
    {
        $admin = Auth::guard('admin')->user();
        $isSuper = $admin instanceof Admin && $admin->isSuperAdmin();
        $tenantId = app(TenantContext::class)->tenantId();
        $singleTenant = app(TenantResolver::class)->tenantCount() === 1;

        if ($tenantId === null && ! $isSuper) {
            abort(403, '当前无法确定租户，请联系管理员。');
        }

        if ($tenantId === null) {
            return $this->globalView($singleTenant);
        }

        return $this->tenantView((int) $tenantId, $isSuper, $singleTenant);
    }

    public function updateQuota(Request $request, int $tenantId): RedirectResponse
    {
        $tenant = Tenant::query()->whereKey($tenantId)->first();
        if ($tenant === null) {
            throw ValidationException::withMessages(['tenant_id' => '所选租户不存在，请刷新后重试。']);
        }

        $data = $request->validate([
            'plan_code' => ['required', 'string', Rule::in(array_keys($this->plans->plans()))],
            'site_limit' => ['nullable', 'integer', 'min:0'],
            'article_quota' => ['nullable', 'integer', 'min:0'],
            'token_budget' => ['nullable', 'integer', 'min:0'],
            'downgrade_model_id' => ['nullable', 'integer', 'exists:ai_models,id'],
        ], [
            'plan_code.required' => '请选择套餐。',
            'plan_code.in' => '所选套餐不存在，请重新选择。',
            'site_limit.integer' => '站点数上限必须是整数。',
            'article_quota.integer' => '月度文章篇数额度必须是整数。',
            'token_budget.integer' => '月度 token 预算必须是整数。',
            'downgrade_model_id.exists' => '所选降级模型不存在，请重新选择。',
        ]);

        $overrides = [];
        foreach (['site_limit', 'article_quota', 'token_budget', 'downgrade_model_id'] as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && $value !== '') {
                $overrides[$key] = (int) $value;
            }
        }

        $settings = (array) ($tenant->settings ?? []);
        $settings['quota'] = $overrides;

        $tenant->forceFill([
            'plan_code' => $data['plan_code'],
            'settings' => $settings,
        ])->save();

        AdminActivityLogger::logFromRequest($request, $request->user('admin'), 'tenant-quota:update', [
            'tenant_id' => (int) $tenant->id,
            'plan_code' => $data['plan_code'],
            'quota' => $overrides,
        ]);

        return back()->with('status', '已更新租户「'.$tenant->name.'」的套餐与额度。');
    }

    private function tenantView(int $tenantId, bool $isSuper, bool $singleTenant): View
    {
        $tenant = Tenant::query()->whereKey($tenantId)->first();
        $period = $this->quota->currentPeriod();

        return view('admin.usage.index', [
            'pageTitle' => __('admin.nav.usage_quota'),
            'scope' => 'tenant',
            'singleTenant' => $singleTenant,
            'tenant' => $tenant,
            'tenantId' => $tenantId,
            'plan' => $this->plans->planFor($tenantId),
            'plans' => $this->plans->plans(),
            'summary' => $this->quota->summary($tenantId),
            'pressure' => $this->quota->pressure($tenantId),
            'reconciliation' => app(TenantQuotaReconciliationService::class)->ensurePeriod($tenantId, $period),
            'periods' => app(TenantQuotaReconciliationService::class)->recentPeriods($tenantId, 6),
            'estimatedCost' => $this->estimatedCost($tenantId),
            'period' => $period,
            'alerts' => TenantQuotaAlert::query()
                ->where('period', $period)
                ->orderByDesc('id')
                ->get(),
            'recentEvents' => TenantUsageEvent::query()
                ->where('period', $period)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
            'canEditQuota' => $isSuper,
            'models' => AiModel::query()->orderBy('name')->get(['id', 'name']),
            'rows' => [],
        ]);
    }

    private function globalView(bool $singleTenant): View
    {
        $period = $this->quota->currentPeriod();

        $rows = app(TenantContext::class)->runAsSystem(function () use ($period): array {
            return Tenant::query()->orderBy('id')->get()->map(function (Tenant $tenant) use ($period): array {
                $tenantId = (int) $tenant->id;

                return [
                    'tenant' => $tenant,
                    'plan' => $this->plans->planFor($tenantId),
                    'summary' => $this->quota->summary($tenantId),
                    'pressure' => $this->quota->pressure($tenantId),
                    'estimated_cost' => $this->estimatedCost($tenantId),
                    'alert_count' => DB::table('tenant_quota_alerts')
                        ->where('tenant_id', $tenantId)
                        ->where('period', $period)
                        ->count(),
                ];
            })->all();
        });

        return view('admin.usage.index', [
            'pageTitle' => __('admin.nav.usage_quota'),
            'scope' => 'global',
            'singleTenant' => $singleTenant,
            'tenant' => null,
            'tenantId' => null,
            'plan' => null,
            'plans' => $this->plans->plans(),
            'summary' => null,
            'pressure' => null,
            'estimatedCost' => 0,
            'period' => $period,
            'alerts' => collect(),
            'recentEvents' => collect(),
            'canEditQuota' => true,
            'models' => app(TenantContext::class)->runAsSystem(
                fn () => AiModel::query()->orderBy('name')->get(['id', 'name'])
            ),
            'rows' => $rows,
        ]);
    }

    private function estimatedCost(int $tenantId): float
    {
        return (float) DB::table('ai_model_usage_events')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('estimated_cost');
    }
}
