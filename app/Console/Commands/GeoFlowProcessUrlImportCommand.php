<?php

namespace App\Console\Commands;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GeoFlowProcessUrlImportCommand extends Command
{
    protected $signature = 'geoflow:process-url-import {jobId : URL import job ID} {--tenant= : 租户 ID；缺省时按任务记录推断}';

    protected $description = 'Process a GEOFlow URL smart import job in the background';

    public function handle(UrlImportProcessingService $service): int
    {
        $job = UrlImportJob::query()->whereKey((int) $this->argument('jobId'))->first();
        if (! $job) {
            $this->error('URL import job not found.');

            return self::FAILURE;
        }

        $tenantId = $this->resolveTenantId((int) $job->tenant_id);
        if ($tenantId === null) {
            return self::INVALID;
        }

        return app(TenantContext::class)->runAs($tenantId, function () use ($service, $job): int {
            if (in_array($job->status, ['completed', 'imported'], true)) {
                $this->info('URL import job already completed.');

                return self::SUCCESS;
            }

            if ((string) $job->status === 'failed' && ! (bool) $job->retryable_failure) {
                $this->error('URL import job cannot be retried: '.((string) $job->error_code ?: 'url_import_failed'));

                return self::FAILURE;
            }

            $job = $service->process($job);
            if ((string) $job->status === 'failed') {
                $this->error('URL import job failed: '.((string) $job->error_code ?: 'url_import_failed'));

                return self::FAILURE;
            }

            $this->info('URL import job processed.');

            return self::SUCCESS;
        });
    }

    /**
     * 解析任务归属租户：显式 --tenant 优先，其次任务记录，最后单租户兜底。
     * 多租户且无法确定归属时明确报错，不静默落默认租户。
     */
    private function resolveTenantId(int $jobTenantId): ?int
    {
        $resolver = app(TenantResolver::class);
        $option = trim((string) $this->option('tenant'));

        if ($option !== '') {
            if (preg_match('/^[1-9][0-9]*$/D', $option) !== 1 || ! $resolver->tenantExists((int) $option)) {
                $this->error(sprintf('租户参数无效：%s。请传入已存在的正整数租户 ID，例如 --tenant=1。', $option));

                return null;
            }

            return (int) $option;
        }

        if ($jobTenantId > 0 && $resolver->tenantExists($jobTenantId)) {
            return $jobTenantId;
        }

        $fallback = $resolver->singleTenantFallbackId();
        if ($fallback !== null) {
            return $fallback;
        }

        Log::error('url_import.tenant_context_missing', [
            'command' => $this->getName(),
            'job_id' => (int) $this->argument('jobId'),
            'tenant_count' => $resolver->tenantCount(),
        ]);
        $this->error('无法确定该 URL 导入任务的归属租户：任务记录缺少租户且当前部署存在多个租户。请使用 --tenant=ID 指定租户后重试。');

        return null;
    }
}
