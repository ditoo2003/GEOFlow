<?php

namespace App\Jobs;

use App\Models\UrlChangeRequest;
use App\Services\Site\UrlChangeReportStore;
use App\Services\Site\UrlChangeService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RecoverUrlChanges implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public function handle(UrlChangeService $changes, UrlChangeReportStore $reports): void
    {
        $context = app(TenantContext::class);

        if ($context->isResolved() && ! $context->isSystem()) {
            $this->recover($changes, $reports);

            return;
        }

        // 调度器以系统上下文入队，这里按租户拆开执行，避免子 job 丢失归属租户。
        foreach (app(TenantResolver::class)->tenantIds() as $tenantId) {
            try {
                $context->runAs($tenantId, fn () => $this->recover($changes, $reports));
            } catch (Throwable $exception) {
                Log::error('url_change.recovery_tenant_failed', [
                    'tenant_id' => $tenantId,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function recover(UrlChangeService $changes, UrlChangeReportStore $reports): void
    {
        foreach (UrlChangeRequest::query()->whereIn('status', ['checking', 'ready', 'applied', 'refreshing'])->where('updated_at', '<', now()->subMinutes(2))->orderBy('updated_at')->limit(100)->get() as $change) {
            if ($change->status === 'ready' && $change->expires_at?->isPast()) {
                Cache::lock('url-change:'.$change->id, 120)->get(fn () => $changes->finish($change, 'stale', __('url_change.errors.expired')));
            } elseif ($change->status === 'checking') {
                CheckUrlChange::dispatch($change->id);
            } elseif (in_array($change->status, ['applied', 'refreshing'], true)) {
                RefreshUrlChange::dispatch($change->id);
            }
        }
        foreach (UrlChangeRequest::query()->whereNull('reports_purged_at')->whereNotNull('finished_at')->where('finished_at', '<', now()->subDay())->whereIn('status', ['completed', 'failed', 'stale', 'cancelled'])->orderBy('finished_at')->limit(100)->get() as $change) {
            Cache::lock('url-change:'.$change->id, 120)->get(function () use ($reports, $change): void {
                if (Storage::disk('local')->deleteDirectory($reports->directory($change))) {
                    $change->forceFill(['reports_purged_at' => now()])->save();
                }
            });
        }
    }
}
