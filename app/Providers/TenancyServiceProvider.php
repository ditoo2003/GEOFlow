<?php

namespace App\Providers;

use App\Database\TenantAwarePostgresConnection;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use App\Tenancy\TenantModelRegistry;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

        // PostgreSQL 连接断开重连后需要重新写入租户会话变量，否则 RLS 会按"未设置上下文"处理。
        DB::resolverFor('pgsql', static fn ($connection, $database, $prefix, $config) => new TenantAwarePostgresConnection(
            $connection,
            $database,
            $prefix,
            $config
        ));
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
     * 队列任务按 payload 中的租户快照恢复上下文。
     *
     * - 入队时把当前上下文写进 payload：租户上下文写 `tenant_id`，系统上下文写 `tenant_system`。
     *   所有分发入口（控制器、服务、命令、事件监听、job 内再分发、batch/chain）都走队列 `push`，
     *   因此统一由 `createPayloadUsing` 覆盖；延迟与重试复用的是同一条 payload，无需额外处理。
     * - 执行前恢复快照，执行后恢复到"入队前"的状态（同步任务不会污染调用方上下文）。
     * - 缺少快照时：单租户部署兜底到唯一租户；多租户部署抛出中文异常并记录结构化日志，绝不静默落默认租户。
     */
    private function registerQueueContext(): void
    {
        BaseQueue::createPayloadUsing(static function (): array {
            $context = app(TenantContext::class);

            if (! $context->isResolved()) {
                return [];
            }

            return $context->isSystem()
                ? ['tenant_system' => true]
                : ['tenant_id' => $context->tenantId()];
        });

        /** @var list<array{job: object, resolved: bool, system: bool, tenant_id: ?int}> $stack */
        $stack = [];

        Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$stack): void {
            $context = app(TenantContext::class);

            $stack[] = [
                'job' => $event->job,
                'resolved' => $context->isResolved(),
                'system' => $context->isSystem(),
                'tenant_id' => $context->tenantId(),
            ];

            $this->applyJobTenantContext($event, $context);
        });

        $restore = static function (object $event) use (&$stack): void {
            if ($stack === []) {
                return;
            }

            $top = end($stack);
            // 同一个任务可能连续触发 JobExceptionOccurred 与 JobFailed，只恢复一次。
            if (($top['job'] ?? null) !== $event->job) {
                return;
            }
            array_pop($stack);

            $context = app(TenantContext::class);
            if (! $top['resolved']) {
                $context->clear();
            } elseif ($top['system']) {
                $context->useSystem();
            } else {
                $context->useTenant((int) $top['tenant_id']);
            }
        };

        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobFailed::class, $restore);
        Event::listen(JobExceptionOccurred::class, $restore);
    }

    private function applyJobTenantContext(JobProcessing $event, TenantContext $context): void
    {
        $payload = $event->job->payload();
        $tenantId = $payload['tenant_id'] ?? null;

        if (is_numeric($tenantId) && (int) $tenantId > 0) {
            $context->useTenant((int) $tenantId);

            return;
        }

        if ((bool) ($payload['tenant_system'] ?? false)) {
            $context->useSystem();

            return;
        }

        $resolver = app(TenantResolver::class);
        $fallback = $resolver->singleTenantFallbackId();
        if ($fallback !== null) {
            $context->useTenant($fallback);

            return;
        }

        $jobName = (string) ($payload['displayName'] ?? $event->job->getName());
        $tenantCount = $resolver->tenantCount();

        Log::error('queue.tenant_context_missing', [
            'job' => $jobName,
            'job_id' => $event->job->getJobId(),
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'tenant_count' => $tenantCount,
        ]);

        throw TenantContextMissingException::forQueuedJob($jobName, $tenantCount);
    }
}
