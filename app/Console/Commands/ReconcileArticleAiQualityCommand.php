<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Jobs\ReconcileArticleAiQualityJob;
use App\Services\GeoFlow\ArticleAiQualityBackfillGuard;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Console\Command;

class ReconcileArticleAiQualityCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:reconcile-ai-quality {--limit=100} {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Queue missing, stale and version-changed article AI quality checks';

    public function handle(
        ArticleAiQualityBackfillGuard $guard,
        ArticleAiQualityReconciliationService $reconciliation,
    ): int {
        return $this->runForEachTenant(fn (): int => $this->reconcile($guard, $reconciliation));
    }

    private function reconcile(
        ArticleAiQualityBackfillGuard $guard,
        ArticleAiQualityReconciliationService $reconciliation,
    ): int {
        $reconciliation->convergeExpired(max(1, min(500, (int) $this->option('limit'))));
        ReconcileArticleAiQualityJob::dispatch($guard->resumeCursor(), 0, max(1, min(500, (int) $this->option('limit'))))
            ->onConnection('redis')
            ->onQueue((string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill'));
        $this->info('AI quality reconciliation queued.');

        return self::SUCCESS;
    }
}
