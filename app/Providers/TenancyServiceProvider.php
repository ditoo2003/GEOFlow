<?php

namespace App\Providers;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantModelRegistry;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * 多租户基础设施装配：上下文单例、模型作用域、创建时租户填充、队列租户快照。
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(TenantResolver::class);
    }

    public function boot(): void
    {
        $this->registerModelScopes();
        $this->registerTenantFilling();
        $this->bootConsoleContext();
        $this->registerQueueContext();
    }

    private function registerModelScopes(): void
    {
        $platformTables = (array) config('tenancy.platform_tables', []);

        foreach (array_keys(TenantModelRegistry::scopedModels()) as $class) {
            /** @var Model $model */
            $model = new $class;
            $model->addGlobalScope(new TenantScope);

            if (in_array($model->getTable(), $platformTables, true)) {
                $this->guardPlatformTemplateWrites($class);
            }
        }
    }

    /**
     * 平台模板行（`tenant_id IS NULL`）对所有租户只读；租户上下文下禁止改删。
     *
     * RLS 的 `tenant_write` 策略在 PostgreSQL 上已经拒绝这类写入，
     * 这里补上不依赖数据库的能力，保证 SQLite/无 RLS 部署同样安全。
     */
    private function guardPlatformTemplateWrites(string $class): void
    {
        $guard = static function (Model $model): void {
            $context = app(TenantContext::class);
            if (! $context->isResolved() || $context->isSystem()) {
                return;
            }

            if ($model->getOriginal('tenant_id') === null) {
                throw new AuthorizationException('平台模板数据对所有租户只读。');
            }
        };

        $class::updating($guard);
        $class::deleting($guard);
    }

    /**
     * 新增记录未显式指定 `tenant_id` 时，从当前上下文补齐；上下文缺失则抛异常。
     */
    private function registerTenantFilling(): void
    {
        Event::listen('eloquent.creating: *', static function (string $event, array $payload): void {
            $model = $payload[0] ?? null;
            if (! $model instanceof Model) {
                return;
            }

            if (! in_array($model->getTable(), (array) config('tenancy.tables', []), true)) {
                return;
            }

            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $context = app(TenantContext::class);
            if ($context->isSystem()) {
                return;
            }

            $model->setAttribute('tenant_id', $context->requireTenantId());
        });
    }

    /**
     * 控制台（artisan、更新器、队列 worker 根上下文）默认使用系统上下文；
     * 单元测试由 TestCase 显式指定默认租户，避免覆盖测试上下文。
     */
    private function bootConsoleContext(): void
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $this->app->make(TenantContext::class)->useSystem();
        }
    }

    /**
     * 队列任务按 payload 中的 `tenant_id` 快照恢复上下文；
     * 多租户环境下缺少快照时保持未解析状态，任务内查询会立刻失败（fail-closed）。
     */
    private function registerQueueContext(): void
    {
        Event::listen(JobProcessing::class, static function (JobProcessing $event): void {
            $context = app(TenantContext::class);
            $payload = $event->job->payload();
            $tenantId = $payload['tenant_id'] ?? null;

            if (is_numeric($tenantId) && (int) $tenantId > 0) {
                $context->useTenant((int) $tenantId);

                return;
            }

            $fallback = app(TenantResolver::class)->singleTenantFallbackId();
            if ($fallback !== null) {
                $context->useTenant($fallback);

                return;
            }

            $context->clear();
        });

        $restoreSystem = static function (): void {
            $context = app(TenantContext::class);
            if ($context->isResolved() && ! $context->isSystem()) {
                $context->useSystem();
            }
        };

        Event::listen(JobProcessed::class, $restoreSystem);
        Event::listen(JobFailed::class, $restoreSystem);
        Event::listen(JobExceptionOccurred::class, $restoreSystem);
    }
}
