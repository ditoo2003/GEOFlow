<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiModel;
use App\Models\AiVisibilityMention;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySampleBatch;
use App\Models\AiVisibilitySource;
use App\Models\AiVisibilityTopicKeyword;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySample;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySampleRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 引擎样本落库：批次、运行记录、引用、提及一次写入。
 *
 * 口径约束：
 * - 新样本一律 `is_legacy = false` 且显式写 `search_enabled`；
 * - 联网/非联网样本按 `search_enabled` 分批次，绝不共用同一批次；
 * - 所有写入显式带 `tenant_id`，不依赖隐式默认租户。
 */
final class VisibilitySamplePersister
{
    public function __construct(
        private readonly VisibilityMentionExtractor $mentionExtractor,
    ) {}

    public function resolveBatch(
        VisibilitySampleRequest $request,
        string $engineKey,
        string $engineVariant,
        int $tenantId,
    ): AiVisibilitySampleBatch {
        return AiVisibilitySampleBatch::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'keyword_hash' => AiVisibilityKeywordNormalizer::hash($request->keyword),
                'engine_key' => $engineKey,
                'engine_variant' => $engineVariant,
                'region' => $request->region,
                'locale' => $request->locale,
                'prompt_template_version' => $request->promptTemplateVersion(),
                'search_enabled' => $request->searchEnabled,
                // period 落库为日期时间，查询条件必须是同一天零点，否则 firstOrCreate 查不到已有批次。
                'period' => now()->startOfDay(),
            ],
            [
                'prompt_template_key' => $request->promptTemplateKey,
                'planned_samples' => max(1, (int) config('geoflow.ai_visibility.default_daily_samples', 3)),
                'status' => AiVisibilitySampleBatch::STATUS_OPEN,
                'started_at' => now(),
            ],
        );
    }

    public function startRun(
        AiVisibilitySampleBatch $batch,
        VisibilitySampleRequest $request,
        string $engineKey,
        string $engineVariant,
        AiModel $model,
        int $tenantId,
    ): AiVisibilityRun {
        $keywordHash = AiVisibilityKeywordNormalizer::hash($request->keyword);

        return AiVisibilityRun::query()->create([
            'tenant_id' => $tenantId,
            'keyword' => $request->keyword,
            'keyword_hash' => $keywordHash,
            'ai_visibility_topic_id' => AiVisibilityTopicKeyword::query()
                ->where('keyword_hash', $keywordHash)
                ->value('ai_visibility_topic_id'),
            'prompt' => $request->promptText,
            'provider_type' => AiVisibilityRun::PROVIDER_ENGINE_SAMPLE,
            'provider_key' => $engineKey,
            'engine_key' => $engineKey,
            'engine_variant' => $engineVariant,
            'collection_method' => AiVisibilityRun::METHOD_API,
            'region' => $request->region,
            'locale' => $request->locale,
            'prompt_template_key' => $request->promptTemplateKey,
            'prompt_template_version' => $request->promptTemplateVersion(),
            'sample_batch_id' => (int) $batch->id,
            'sample_index' => $request->sampleIndex
                ?? ((int) $batch->completed_samples + (int) $batch->failed_samples + 1),
            'attempt_no' => $request->attemptNo,
            'search_enabled' => $request->searchEnabled,
            'is_legacy' => false,
            'ai_model_id' => (int) $model->id,
            'model_id' => (string) ($model->model_id ?? ''),
            'status' => AiVisibilityRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  list<array{entity_type?:string,entity_key:string,canonical_name:string,aliases?:list<string>}>  $entities
     */
    public function complete(
        AiVisibilityRun $run,
        AiVisibilitySampleBatch $batch,
        VisibilitySample $sample,
        array $entities = [],
    ): AiVisibilityRun {
        return DB::transaction(function () use ($run, $batch, $sample, $entities): AiVisibilityRun {
            $locked = AiVisibilityRun::query()->whereKey((int) $run->id)->lockForUpdate()->first();
            if (! $locked instanceof AiVisibilityRun || (string) $locked->status !== AiVisibilityRun::STATUS_RUNNING) {
                throw new AiVisibilityRunDiscardedException('ai_result_discarded');
            }

            $locked->update([
                'status' => AiVisibilityRun::STATUS_COMPLETED,
                'answer_text' => $sample->answerText !== '' ? $sample->answerText : null,
                'latency_ms' => $sample->latencyMs,
                'usage_json' => $sample->usage !== [] ? $sample->usage : null,
                'raw_request_json' => $sample->rawRequest !== [] ? $sample->rawRequest : null,
                'raw_response_json' => $sample->rawResponse !== [] ? $sample->rawResponse : null,
                'http_status' => $sample->httpStatus,
                'cost_estimate_micros' => $sample->costEstimateMicros,
                'error_message' => null,
                'completed_at' => now(),
            ]);

            AiVisibilitySource::query()->where('ai_visibility_run_id', (int) $locked->id)->delete();
            foreach ($sample->citations as $citation) {
                AiVisibilitySource::query()->create(array_merge(
                    ['tenant_id' => (int) $locked->tenant_id, 'ai_visibility_run_id' => (int) $locked->id],
                    $citation->toSourceAttributes(),
                ));
            }

            AiVisibilityMention::query()->where('ai_visibility_run_id', (int) $locked->id)->delete();
            foreach ($this->mentionExtractor->extract($sample->answerText, $entities) as $mention) {
                AiVisibilityMention::query()->create(array_merge(
                    ['tenant_id' => (int) $locked->tenant_id, 'ai_visibility_run_id' => (int) $locked->id],
                    $mention->toDatabaseAttributes(),
                ));
            }

            $this->advanceBatch($batch, completed: $sample->isCompleted());

            $run->setRawAttributes($locked->getAttributes(), true);

            return $run;
        });
    }

    public function fail(AiVisibilityRun $run, AiVisibilitySampleBatch $batch, string $errorCode): void
    {
        DB::transaction(function () use ($run, $batch, $errorCode): void {
            AiVisibilityRun::query()
                ->whereKey((int) $run->id)
                ->where('status', AiVisibilityRun::STATUS_RUNNING)
                ->update([
                    'status' => AiVisibilityRun::STATUS_FAILED,
                    'error_message' => $errorCode,
                    'completed_at' => now(),
                ]);

            $this->advanceBatch($batch, completed: false);
        });

        $run->forceFill([
            'status' => AiVisibilityRun::STATUS_FAILED,
            'error_message' => $errorCode,
            'completed_at' => now(),
        ]);
    }

    private function advanceBatch(AiVisibilitySampleBatch $batch, bool $completed): void
    {
        $locked = AiVisibilitySampleBatch::query()->whereKey((int) $batch->id)->lockForUpdate()->first();
        if (! $locked instanceof AiVisibilitySampleBatch) {
            throw new RuntimeException('ai_visibility_batch_missing');
        }

        $completedSamples = (int) $locked->completed_samples + ($completed ? 1 : 0);
        $failedSamples = (int) $locked->failed_samples + ($completed ? 0 : 1);
        $planned = max(1, (int) $locked->planned_samples);
        $finished = $completedSamples + $failedSamples >= $planned;

        $locked->update([
            'completed_samples' => $completedSamples,
            'failed_samples' => $failedSamples,
            'status' => $finished
                ? ($completedSamples > 0 ? AiVisibilitySampleBatch::STATUS_COMPLETED : AiVisibilitySampleBatch::STATUS_FAILED)
                : AiVisibilitySampleBatch::STATUS_OPEN,
            'completed_at' => $finished ? now() : null,
        ]);

        $batch->setRawAttributes($locked->getAttributes(), true);
    }
}
