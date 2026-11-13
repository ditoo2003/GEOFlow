<?php

namespace App\Console\Concerns;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 控制台命令的租户迭代。
 *
 * 调度器与 CLI 默认运行在系统上下文中：跨租户读取可见，但新建隔离表数据拿不到归属租户。
 * 这里把命令体逐租户执行，使写入落到正确租户，而不是落空或统一落到默认租户。
 *
 * 用法：命令签名加 `{--tenant=*}`，`handle()` 用 `$this->runForEachTenant(...)` 包裹原有逻辑。
 */
trait IteratesTenants
{
    /**
     * 逐租户执行命令体；单个租户失败不影响其余租户。
     *
     * @param  callable(int): (int|null)  $callback  返回命令退出码，null 视为成功
     */
    protected function runForEachTenant(callable $callback): int
    {
        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === null) {
            return self::INVALID;
        }

        if ($tenantIds === []) {
            $this->components->warn('当前没有可用租户，命令未执行。');

            return self::SUCCESS;
        }

        $context = app(TenantContext::class);
        // JSON 输出必须是单个可解析文档，不能混入逐租户进度行。
        $showTenant = count($tenantIds) > 1 && ! $this->isJsonOutput();
        $failedTenants = [];
        $exitCode = self::SUCCESS;

        foreach ($tenantIds as $tenantId) {
            if ($showTenant) {
                $this->line(sprintf('租户 #%d ...', $tenantId));
            }

            try {
                $code = $context->runAs($tenantId, static fn () => $callback($tenantId));
                if (is_int($code) && $code !== self::SUCCESS) {
                    $exitCode = self::FAILURE;
                }
            } catch (Throwable $exception) {
                $exitCode = self::FAILURE;
                $failedTenants[] = $tenantId;

                Log::error('console.tenant_iteration_failed', [
                    'command' => $this->getName(),
                    'tenant_id' => $tenantId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                $this->components->error(sprintf('租户 #%d 执行失败：%s', $tenantId, $exception->getMessage()));
            }
        }

        if ($failedTenants !== []) {
            $this->components->warn(
                '以下租户本次执行失败，修复后可单独重跑（--tenant=ID）：'
                .implode('、', array_map(static fn (int $id): string => '#'.$id, $failedTenants))
            );
        }

        return $exitCode;
    }

    /**
     * 只在一个租户内执行；多租户且未显式 --tenant 时报中文错误，避免"猜一个"往下跑。
     *
     * @param  callable(int): (int|null)  $callback
     */
    protected function runForSingleTenant(callable $callback): int
    {
        $tenantIds = $this->resolveTenantIds();
        if ($tenantIds === null) {
            return self::INVALID;
        }

        if (count($tenantIds) > 1) {
            $this->components->error('该命令一次只能处理一个租户，请使用 --tenant=ID 明确指定。');

            return self::INVALID;
        }

        if ($tenantIds === []) {
            $this->components->warn('当前没有可用租户，命令未执行。');

            return self::SUCCESS;
        }

        $tenantId = $tenantIds[0];

        try {
            return (int) app(TenantContext::class)->runAs($tenantId, static fn () => $callback($tenantId));
        } catch (Throwable $exception) {
            Log::error('console.tenant_iteration_failed', [
                'command' => $this->getName(),
                'tenant_id' => $tenantId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->components->error(sprintf('租户 #%d 执行失败：%s', $tenantId, $exception->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * 解析 `--tenant=`：支持单个 ID、逗号分隔或重复传参；缺省遍历全部租户。
     *
     * @return list<int>|null null 表示参数非法（已输出中文错误）
     */
    protected function resolveTenantIds(): ?array
    {
        $resolver = app(TenantResolver::class);
        $option = $this->tenantOptionName();

        if (! $this->input->hasOption($option)) {
            return $resolver->tenantIds();
        }

        $raw = $this->option($option);
        $values = is_array($raw) ? $raw : [$raw];
        $requested = [];

        foreach ($values as $value) {
            foreach (preg_split('/\s*,\s*/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (preg_match('/^[1-9][0-9]*$/D', $part) !== 1) {
                    $this->components->error(sprintf(
                        '租户参数无效：%s。请传入正整数租户 ID（例如 --tenant=1），或省略该参数以遍历全部租户。',
                        $part,
                    ));

                    return null;
                }

                $tenantId = (int) $part;
                if (! $resolver->tenantExists($tenantId)) {
                    $this->components->error(sprintf('租户 %d 不存在，请先在租户管理中确认租户 ID。', $tenantId));

                    return null;
                }

                $requested[$tenantId] = $tenantId;
            }
        }

        return $requested === [] ? $resolver->tenantIds() : array_values($requested);
    }

    protected function tenantOptionName(): string
    {
        return 'tenant';
    }

    /** 命令声明了 `--json` 且本次调用启用时，stdout 只允许结构化输出。 */
    protected function isJsonOutput(): bool
    {
        return $this->input->hasOption('json') && (bool) $this->option('json');
    }
}
