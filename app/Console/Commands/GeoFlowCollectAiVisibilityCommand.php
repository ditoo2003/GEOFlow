<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Data\Ai\SystemAiIdentity;
use App\Jobs\DetectAiVisibilityCompetitorsJob;
use App\Services\GeoFlow\AiVisibility\AiVisibilityCollectionService;
use Illuminate\Console\Command;
use Throwable;

class GeoFlowCollectAiVisibilityCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:ai-visibility:collect
                            {keywords* : One or more keywords to collect}
                            {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Collect AI visibility search and analysis runs with saved provider bindings';

    public function handle(AiVisibilityCollectionService $collection): int
    {
        $keywords = array_values(array_unique(array_filter(
            array_map(static fn (mixed $keyword): string => trim((string) $keyword), (array) $this->argument('keywords')),
        )));
        if ($keywords === []) {
            $this->error('At least one keyword is required.');

            return self::FAILURE;
        }

        return $this->runForEachTenant(fn (): int => $this->collect($collection, $keywords));
    }

    /**
     * @param  list<string>  $keywords
     */
    private function collect(AiVisibilityCollectionService $collection, array $keywords): int
    {
        $failed = 0;
        $identity = SystemAiIdentity::forVisibilityCollection();
        foreach ($keywords as $keyword) {
            try {
                $runs = $collection->collect($identity, $keyword);
                foreach ($runs as $run) {
                    if (trim((string) $run->answer_text) !== '') {
                        DetectAiVisibilityCompetitorsJob::dispatch((int) $run->id);
                    }
                }
                $this->info(sprintf('AI visibility collected: keyword=%s, runs=%d', $keyword, count($runs)));
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->error(sprintf('AI visibility collection failed: keyword=%s', $keyword));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
