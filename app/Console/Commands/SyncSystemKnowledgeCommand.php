<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\AiWorkspace\SystemKnowledgeMediaManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Console\Command;
use Throwable;

final class SyncSystemKnowledgeCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:sync-system-knowledge
        {--key=ai_workspace_manual : Stable system knowledge key}
        {--media : Import the bundled, hash-verified knowledge screenshots}
        {--verify : Read only and verify the synchronized official version or preserved customization}
        {--json : Emit a structured synchronization report}
        {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Create or safely update GEOFlow system knowledge without overwriting customized content';

    public function handle(SystemKnowledgeBaseManager $manager, SystemKnowledgeMediaManager $media): int
    {
        if ($this->option('verify')) {
            return $this->verify($manager);
        }

        return $this->runForEachTenant(fn (): int => $this->sync($manager, $media));
    }

    /**
     * 系统知识库绑定在租户隔离表上，必须逐租户校验；
     * 多租户时输出单个聚合 JSON，保持升级校验的 json_decode 兼容。
     */
    private function verify(SystemKnowledgeBaseManager $manager): int
    {
        $key = trim((string) $this->option('key'));
        $definition = $manager->definition($key);
        $context = app(TenantContext::class);
        $statuses = [];

        foreach (app(TenantResolver::class)->tenantIds() as $tenantId) {
            $statuses[$tenantId] = $context->runAs($tenantId, function () use ($manager, $key, $definition): string {
                $binding = $manager->binding($key);
                $complete = $binding !== null && $binding->knowledgeBase !== null
                    && $binding->official_version === $definition['official_version']
                    && hash_equals((string) $binding->official_content_hash, $definition['content_hash'])
                    && ($binding->customized_at !== null || hash_equals($definition['content_hash'], hash('sha256', (string) $binding->knowledgeBase->content)));

                return $complete ? 'pass' : 'fail';
            });
        }

        $status = $statuses !== [] && ! in_array('fail', $statuses, true) ? 'pass' : 'fail';
        $report = ['schema_version' => 1, 'status' => $status, 'key' => $key];
        if (count($statuses) > 1) {
            $report['tenants'] = array_map(
                static fn (int $tenantId, string $value): array => ['tenant_id' => $tenantId, 'status' => $value],
                array_keys($statuses),
                array_values($statuses),
            );
        }

        $this->line($this->option('json') ? json_encode($report, JSON_THROW_ON_ERROR) : 'System knowledge: '.$report['status']);

        return $status === 'pass' ? self::SUCCESS : self::FAILURE;
    }

    private function sync(SystemKnowledgeBaseManager $manager, SystemKnowledgeMediaManager $media): int
    {
        try {
            $result = $manager->sync(trim((string) $this->option('key')));
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $state = $result['created']
            ? 'created'
            : ($result['updated'] ? 'updated' : ($result['customized'] ? 'customized-preserved' : 'current'));
        $this->components->info(sprintf(
            'System knowledge [%s] is %s; index request: %s.',
            (string) $result['binding']->system_key,
            $state,
            $result['index_requested'] ? 'queued' : 'unchanged',
        ));

        if ((bool) $this->option('media')) {
            try {
                $mediaResult = $media->syncBundled();
                $this->components->info(sprintf(
                    'Knowledge media synchronized: %d imported, %d updated, %d unchanged.',
                    $mediaResult['imported'],
                    $mediaResult['updated'],
                    $mediaResult['unchanged'],
                ));
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
