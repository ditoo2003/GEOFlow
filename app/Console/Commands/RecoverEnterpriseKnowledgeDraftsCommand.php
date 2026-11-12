<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftRecoveryService;
use Illuminate\Console\Command;

final class RecoverEnterpriseKnowledgeDraftsCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:recover-enterprise-knowledge-drafts {--limit=50} {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Requeue stale enterprise knowledge draft generations';

    public function handle(EnterpriseKnowledgeDraftRecoveryService $recovery): int
    {
        return $this->runForEachTenant(fn (): int => $this->recover($recovery));
    }

    private function recover(EnterpriseKnowledgeDraftRecoveryService $recovery): int
    {
        $result = $recovery->reconcile(max(1, min(500, (int) $this->option('limit'))));
        $this->info(sprintf(
            'Recovered enterprise knowledge drafts: %d; dispatch failures: %d',
            $result['recovered'],
            $result['dispatch_failed'],
        ));

        return $result['dispatch_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
