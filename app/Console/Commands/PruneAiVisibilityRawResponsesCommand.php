<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\AiVisibilityRun;
use Illuminate\Console\Command;

/**
 * 多引擎可见性原始回答留存清理。
 *
 * 只清空原始请求/回答正文，保留引用、提及与统计字段：
 * 聚合口径与客户导出（聚合 + 引用列表）不受影响，但无法再还原原始全文。
 */
final class PruneAiVisibilityRawResponsesCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:prune-ai-visibility-raw
        {--days= : Retention period in days}
        {--dry-run : Report the runs that are eligible without redacting data}
        {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Redact expired AI visibility raw prompts and responses';

    public function handle(): int
    {
        return $this->runForEachTenant(fn (): int => $this->prune());
    }

    private function prune(): int
    {
        $days = max(1, (int) ($this->option('days') ?: config('geoflow.ai_visibility.raw_retention_days', 90)));
        $cutoff = now()->subDays($days);

        $query = AiVisibilityRun::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $cutoff)
            ->whereNull('raw_pruned_at')
            ->where(function ($builder): void {
                $builder->whereNotNull('answer_text')
                    ->orWhereNotNull('raw_request_json')
                    ->orWhereNotNull('raw_response_json');
            });

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d visibility runs are eligible for raw payload pruning.', (clone $query)->count()));

            return self::SUCCESS;
        }

        $pruned = 0;
        $query->select('id')->chunkById(500, function ($runs) use (&$pruned): void {
            $ids = $runs->pluck('id')->all();
            if ($ids === []) {
                return;
            }

            $pruned += AiVisibilityRun::query()->whereKey($ids)->update([
                'answer_text' => null,
                'raw_request_json' => null,
                'raw_response_json' => null,
                'raw_pruned_at' => now(),
            ]);
        });

        $this->info(sprintf('Pruned raw payloads for %d visibility runs.', $pruned));

        return self::SUCCESS;
    }
}
