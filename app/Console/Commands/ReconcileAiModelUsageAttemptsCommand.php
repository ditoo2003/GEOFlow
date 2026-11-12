<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Services\Admin\AiModelUsageAttemptReconciler;
use Illuminate\Console\Command;

final class ReconcileAiModelUsageAttemptsCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:reconcile-ai-usage-attempts
        {--older-than=900 : Minimum attempt age in seconds}
        {--batch=200 : Rows processed per batch}
        {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Append terminal failures for stale AI provider attempts without outcomes';

    public function handle(AiModelUsageAttemptReconciler $reconciler): int
    {
        return $this->runForEachTenant(fn (): int => $this->reconcile($reconciler));
    }

    private function reconcile(AiModelUsageAttemptReconciler $reconciler): int
    {
        $olderThan = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($olderThan === false || $olderThan < 1 || $batch === false || $batch < 1 || $batch > 1000) {
            $this->error('Invalid reconciliation bounds.');

            return self::INVALID;
        }

        $count = $reconciler->reconcile($olderThan, $batch);
        $this->line('Stale AI usage attempts reconciled: '.$count);

        return self::SUCCESS;
    }
}
