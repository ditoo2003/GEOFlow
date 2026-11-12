<?php

namespace Tests\Support;

use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 队列租户上下文测试探针：记录执行时看到的租户，可再分发一个同步子任务。
 */
class TenantProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var array<string, int|string|null> */
    public static array $observed = [];

    public function __construct(
        public string $label = 'probe',
        public bool $dispatchNested = false,
    ) {}

    public function handle(): void
    {
        $context = app(TenantContext::class);
        static::$observed[$this->label] = $context->isSystem() ? 'system' : $context->tenantId();

        if ($this->dispatchNested) {
            dispatch_sync(new self($this->label.'-nested'));
        }
    }
}
