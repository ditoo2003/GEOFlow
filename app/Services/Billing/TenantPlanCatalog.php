<?php

namespace App\Services\Billing;

use App\Models\Tenant;

/**
 * 套餐目录解析：套餐默认值来自 `config('billing.plans')`，
 * 租户可用 `tenants.plan_code` 选择套餐，并用 `tenants.settings['quota']` 覆盖单项额度。
 *
 * 未知 `plan_code` 回落默认套餐而不是报错，避免历史数据阻塞后台。
 */
final class TenantPlanCatalog
{
    /** @return array{code: string, label: string, site_limit: int, article_quota: int, token_budget: int, visibility_sample_quota: int, downgrade_model_id: ?int} */
    public function planFor(int $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $plans = (array) config('billing.plans', []);
        $defaultCode = (string) config('billing.default_plan', 'free');
        $code = (string) ($tenant?->plan_code ?: $defaultCode);

        if (! isset($plans[$code])) {
            $code = isset($plans[$defaultCode]) ? $defaultCode : (string) array_key_first($plans);
        }

        $plan = (array) ($plans[$code] ?? []);
        $overrides = (array) data_get($tenant?->settings, 'quota', []);

        return [
            'code' => $code,
            'label' => (string) ($plan['label'] ?? ucfirst($code)),
            'site_limit' => $this->intValue($overrides['site_limit'] ?? null, (int) ($plan['site_limit'] ?? 0)),
            'article_quota' => $this->intValue($overrides['article_quota'] ?? null, (int) ($plan['article_quota'] ?? 0)),
            'token_budget' => $this->intValue($overrides['token_budget'] ?? null, (int) ($plan['token_budget'] ?? 0)),
            'visibility_sample_quota' => $this->intValue($overrides['visibility_sample_quota'] ?? null, (int) ($plan['visibility_sample_quota'] ?? 0)),
            'downgrade_model_id' => $this->intValue($overrides['downgrade_model_id'] ?? null, (int) ($plan['downgrade_model_id'] ?? 0)) ?: null,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function plans(): array
    {
        return (array) config('billing.plans', []);
    }

    public function siteLimit(int $tenantId): int
    {
        return $this->planFor($tenantId)['site_limit'];
    }

    public function articleQuota(int $tenantId): int
    {
        return $this->planFor($tenantId)['article_quota'];
    }

    public function tokenBudget(int $tenantId): int
    {
        return $this->planFor($tenantId)['token_budget'];
    }

    public function downgradeModelId(int $tenantId): ?int
    {
        return $this->planFor($tenantId)['downgrade_model_id'];
    }

    public function visibilitySampleQuota(int $tenantId): int
    {
        return $this->planFor($tenantId)['visibility_sample_quota'];
    }

    private function intValue(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? max(0, (int) $value) : $fallback;
    }
}
