<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Jobs\ReconcileArticleAiOptimizationJob;
use Illuminate\Console\Command;

final class ReconcileArticleAiOptimizationCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:reconcile-ai-optimization {--limit=500} {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Recover stale AI quality optimization runs and pending workflows';

    public function handle(): int
    {
        return $this->runForEachTenant(fn (): int => $this->reconcile());
    }

    private function reconcile(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        ReconcileArticleAiOptimizationJob::dispatch($limit)
            ->onConnection('redis')
            ->onQueue((string) config('geoflow.ai_quality_optimization_bulk_queue', 'ai-content-optimization-bulk'));
        $this->info('AI quality optimization reconciliation queued.');

        return self::SUCCESS;
    }
}
