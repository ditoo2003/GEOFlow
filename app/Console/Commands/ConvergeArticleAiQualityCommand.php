<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Console\Command;

class ConvergeArticleAiQualityCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:converge-ai-quality {--limit=100} {--json} {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Synchronously close expired AI quality checks and resume completed workflows';

    public function handle(ArticleAiQualityReconciliationService $reconciliation): int
    {
        return $this->runForEachTenant(fn (): int => $this->converge($reconciliation));
    }

    private function converge(ArticleAiQualityReconciliationService $reconciliation): int
    {
        $result = $reconciliation->convergeExpired((int) $this->option('limit'));

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'AI quality convergence finished: %d expired, %d workflows resumed.',
                $result['expired'],
                $result['workflows'],
            ));
        }

        return self::SUCCESS;
    }
}
