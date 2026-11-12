<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Eloquent 全局作用域：给所有纳入隔离的表追加 `tenant_id` 条件。
 *
 * 平台模板表（`tenant_id IS NULL`）对所有租户只读可见。
 * 上下文未初始化时直接抛异常，避免"默认放行"造成静默串数据。
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isSystem()) {
            return;
        }

        if (! $context->isResolved()) {
            throw TenantContextMissingException::forTable($model->getTable());
        }

        $column = $model->qualifyColumn('tenant_id');
        $tenantId = $context->requireTenantId();

        if (in_array($model->getTable(), (array) config('tenancy.platform_tables', []), true)) {
            $builder->where(static function (Builder $query) use ($column, $tenantId): void {
                $query->where($column, $tenantId)->orWhereNull($column);
            });

            return;
        }

        $builder->where($column, $tenantId);
    }
}
