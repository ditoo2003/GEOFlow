<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Services\GeoFlow\TitleGenerationCoordinator;
use Illuminate\Console\Command;

class RecoverTitleGenerationRunsCommand extends Command
{
    use IteratesTenants;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geoflow:recover-title-generations {--limit=100} {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Requeue stalled AI title generation batches';

    /**
     * Execute the console command.
     */
    public function handle(TitleGenerationCoordinator $coordinator): int
    {
        return $this->runForEachTenant(fn (): int => $this->recover($coordinator));
    }

    private function recover(TitleGenerationCoordinator $coordinator): int
    {
        $recovered = $coordinator->recoverStalled(
            max(1, min(500, (int) $this->option('limit'))),
        );
        $this->info(sprintf('Recovered title generation runs: %d', $recovered));

        return self::SUCCESS;
    }
}
