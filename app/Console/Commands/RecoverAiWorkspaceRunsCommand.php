<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Services\AiWorkspace\AiWorkflowEngine;
use App\Services\AiWorkspace\AiWorkspaceCoordinator;
use Illuminate\Console\Command;

final class RecoverAiWorkspaceRunsCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:recover-ai-workspace {--limit=50} {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Recover AI workspace runs whose execution leases expired';

    public function handle(AiWorkspaceCoordinator $coordinator, AiWorkflowEngine $engine): int
    {
        return $this->runForEachTenant(fn (): int => $this->recover($coordinator, $engine));
    }

    private function recover(AiWorkspaceCoordinator $coordinator, AiWorkflowEngine $engine): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $resolutionRuns = $coordinator->recoverExpiredResolutions($limit);
        $executionRuns = $resolutionRuns < $limit
            ? $engine->recoverExpiredExecutions($limit - $resolutionRuns)
            : 0;

        $this->info(sprintf(
            'Recovered AI workspace runs: %d',
            $resolutionRuns + $executionRuns,
        ));

        return self::SUCCESS;
    }
}
