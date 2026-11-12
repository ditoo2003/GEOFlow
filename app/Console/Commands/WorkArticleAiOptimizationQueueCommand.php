<?php

namespace App\Console\Commands;

use App\Ai\Adapter\TimeoutChainValidator;
use Illuminate\Console\Command;

final class WorkArticleAiOptimizationQueueCommand extends Command
{
    protected $signature = 'geoflow:work-ai-optimization {--validate : Validate queue and timeout configuration without starting a worker}';

    protected $description = 'Run the prioritized AI content optimization queues';

    public function handle(): int
    {
        $frontQueue = (string) config('geoflow.ai_quality_optimization_queue', 'ai-content-optimization');
        $bulkQueue = (string) config('geoflow.ai_quality_optimization_bulk_queue', 'ai-content-optimization-bulk');
        if ($frontQueue === '' || $bulkQueue === '' || $frontQueue === $bulkQueue) {
            $this->components->error('AI optimization queues must be non-empty and distinct.');

            return self::FAILURE;
        }

        $validator = new TimeoutChainValidator;
        $chain = $validator->optimizationChain();
        if ($validator->violations($chain) !== []) {
            $this->components->error('AI optimization timeout chain must satisfy business < job < worker < retry_after.');

            return self::FAILURE;
        }
        if ((bool) $this->option('validate')) {
            $this->components->info('AI optimization worker configuration is valid.');

            return self::SUCCESS;
        }

        return $this->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $frontQueue.','.$bulkQueue,
            '--sleep' => 1,
            '--tries' => 1,
            '--timeout' => $chain['worker'],
            '--memory' => 192,
            '--max-jobs' => 25,
            '--max-time' => 3600,
            '--force' => true,
        ]);
    }
}
