<?php

namespace App\Tenancy;

use RuntimeException;

class TenantContextMissingException extends RuntimeException
{
    public static function forTable(string $table): self
    {
        return new self(sprintf('租户上下文缺失，拒绝访问表 [%s]。', $table));
    }

    public static function forQueuedJob(string $job, int $tenantCount): self
    {
        return new self(sprintf(
            '队列任务 [%s] 缺少租户快照（tenant_id），当前系统存在 %d 个租户，已拒绝执行以避免跨租户串数据。请从带租户上下文的入口重新分发该任务。',
            $job,
            $tenantCount
        ));
    }
}
