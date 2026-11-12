<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Topic;
use App\Services\Topics\TopicFreshnessService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class CheckTopicTemporalState extends Command
{
    use IteratesTenants;

    protected $signature = 'geoflow:check-topic-temporal-state {--limit=500} {--tenant=* : 只处理指定租户，缺省遍历全部租户}';

    protected $description = 'Record published topic time-window changes and review reminders';

    public function handle(TopicFreshnessService $freshness): int
    {
        return $this->runForEachTenant(fn (): int => $this->check($freshness));
    }

    private function check(TopicFreshnessService $freshness): int
    {
        $changed = 0;
        Topic::query()->whereNotNull('public_revision_id')
            ->where(fn ($query) => $query->whereNull('freshness_checked_at')
                ->orWhereColumn('freshness_revision_id', '!=', 'public_revision_id')
                ->orWhere('freshness_next_check_at', '<=', CarbonImmutable::now('UTC')))
            ->with('publicRevision')->orderBy('id')->limit(max(1, min(5000, (int) $this->option('limit'))))
            ->get()->each(function (Topic $topic) use ($freshness, &$changed): void {
                $revision = $topic->publicRevision;
                if ($revision === null) {
                    return;
                }
                $snapshot = $revision->freshness_snapshot_json ?? $revision->payload['freshness_snapshot_json'] ?? ['supported' => false];
                if ($freshness->recordState($topic, $revision, $freshness->evaluate($snapshot))) {
                    $changed++;
                }
            });
        $this->info('专题时效状态变更：'.$changed);

        return self::SUCCESS;
    }
}
