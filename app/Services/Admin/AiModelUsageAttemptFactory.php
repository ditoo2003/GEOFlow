<?php

namespace App\Services\Admin;

use App\Ai\Adapter\CallScene;
use App\Ai\Adapter\ModelEndpoint;
use App\Ai\Adapter\ModelEndpointResolver;
use App\Ai\Adapter\ProviderOptionsBuilder;
use App\Data\Admin\AdminAiModelTestSnapshot;
use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class AiModelUsageAttemptFactory
{
    public function __construct(
        private AiModelUsageRecorder $recorder,
        private AiModelUsageAttemptStartRecorder $startRecorder,
        private ProviderOptionsBuilder $optionsBuilder,
    ) {}

    public function requestId(): string
    {
        return (string) Str::uuid();
    }

    public function beginForAdmin(
        AiModel $model,
        int $executionAdminId,
        int $accessVersion,
        string $executionScope,
        string $modelSource,
        string $requestId,
        string $requestPayload,
        string $callKey,
        string $operation,
        string $businessSource,
        ?string $sourceType = null,
        int|string|null $sourceId = null,
        ?CallScene $scene = null,
        ?int $maxTokensApplied = null,
        ?int $attempt = null,
    ): AiModelUsageAttempt {
        $snapshot = null;
        try {
            $admin = new Admin;
            $admin->setAttribute($admin->getKeyName(), $executionAdminId);
            $snapshot = AiModelUsageAccessSnapshot::capture(
                model: $model,
                executionAdmin: $admin,
                executionScope: $executionScope,
                modelSource: $modelSource,
                aiConfigAccessVersion: $accessVersion,
                requestId: $requestId,
                requestPayloadDigest: hash('sha256', $requestPayload),
            );
        } catch (Throwable $exception) {
            $this->warnSafely($exception);
        }

        return $this->attempt($snapshot, [
            'call_key' => $callKey,
            'operation' => $operation,
            'business_source' => $businessSource,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ], $model, $scene, $maxTokensApplied, $attempt, $requestPayload);
    }

    public function beginForSystem(
        AiModel $model,
        SystemAiIdentity $identity,
        string $requestId,
        string $requestPayload,
        string $callKey,
        string $operation,
        string $businessSource,
        ?string $sourceType = null,
        int|string|null $sourceId = null,
        ?CallScene $scene = null,
    ): AiModelUsageAttempt {
        $identity->assertCanBuildKnowledgeIndex();
        $snapshot = AiModelUsageAccessSnapshot::capture(
            model: $model,
            executionAdmin: null,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            modelSource: AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            aiConfigAccessVersion: 0,
            requestId: $requestId,
            requestPayloadDigest: hash('sha256', $requestPayload),
        );

        return $this->attempt($snapshot, [
            'call_key' => $callKey,
            'operation' => $operation,
            'business_source' => $businessSource,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ], $model, $scene, null, null, $requestPayload);
    }

    public function beginForGovernanceTest(
        AiModel $model,
        AdminAiModelTestSnapshot $testSnapshot,
        string $requestId,
        string $requestPayload,
        string $callKey,
        string $operation = 'governance.model_connection_test',
        string $businessSource = 'governance_model_test',
        ?CallScene $scene = CallScene::Probe,
    ): AiModelUsageAttempt {
        $snapshot = null;
        try {
            $snapshot = AiModelUsageAccessSnapshot::captureForGovernanceTest(
                model: $model,
                testSnapshot: $testSnapshot,
                requestId: $requestId,
                requestPayloadDigest: hash('sha256', $requestPayload),
            );
        } catch (Throwable $exception) {
            $this->warnSafely($exception);
        }

        return $this->attempt($snapshot, [
            'call_key' => $callKey,
            'operation' => $operation,
            'business_source' => $businessSource,
            'source_type' => AiModel::class,
            'source_id' => (int) $model->getKey(),
        ], $model, $scene, null, null, $requestPayload);
    }

    public function beginForVisibilityCollection(
        AiModel $model,
        SystemAiIdentity $identity,
        string $requestId,
        string $requestPayload,
        string $callKey,
        string $operation,
        string $businessSource,
        ?string $sourceType = null,
        int|string|null $sourceId = null,
        ?CallScene $scene = null,
    ): AiModelUsageAttempt {
        $identity->assertCanCollectVisibility();
        $snapshot = AiModelUsageAccessSnapshot::capture(
            model: $model,
            executionAdmin: null,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            modelSource: AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            aiConfigAccessVersion: 0,
            requestId: $requestId,
            requestPayloadDigest: hash('sha256', $requestPayload),
        );

        return $this->attempt($snapshot, [
            'call_key' => $callKey,
            'operation' => $operation,
            'business_source' => $businessSource,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ], $model, $scene, null, null, $requestPayload);
    }

    public function sourceFor(AiModel $model, int $executionAdminId): string
    {
        return (int) $model->owner_admin_id === $executionAdminId
            ? AiModelUsageEvent::MODEL_SOURCE_PERSONAL
            : AiModelUsageEvent::MODEL_SOURCE_SHARED;
    }

    /**
     * @param  array{call_key:string,operation:string,business_source:string,source_type:?string,source_id:int|string|null}  $identity
     */
    private function attempt(
        ?AiModelUsageAccessSnapshot $snapshot,
        array $identity,
        AiModel $model,
        ?CallScene $scene = null,
        ?int $maxTokensApplied = null,
        ?int $attempt = null,
        ?string $requestPayload = null,
    ): AiModelUsageAttempt {
        $observation = $this->observation($model, $scene, $maxTokensApplied, $attempt, (string) $identity['call_key'], $requestPayload);
        if ($snapshot instanceof AiModelUsageAccessSnapshot) {
            $this->startRecorder->record($snapshot, [...$identity, ...$observation]);
        }

        $usageAttempt = new AiModelUsageAttempt($snapshot, $this->recorder, $identity);
        if ($observation !== []) {
            $usageAttempt->context($observation);
        }

        return $usageAttempt;
    }

    /**
     * 从接入点与场景推导账本观测字段；解析失败时返回空数组，保持列 NULL。
     *
     * @return array<string, mixed>
     */
    private function observation(
        AiModel $model,
        ?CallScene $scene,
        ?int $maxTokensApplied,
        ?int $attempt,
        string $callKey,
        ?string $requestPayload = null,
    ): array {
        $observation = [];

        $endpoint = $this->resolveEndpoint($model);
        if ($endpoint instanceof ModelEndpoint) {
            $observation['protocol'] = $endpoint->protocol->value;
            $observation['thinking_mode'] = $this->thinkingMode($endpoint, $scene, $requestPayload);
        }

        $maxTokens = $maxTokensApplied ?? (is_numeric($model->max_tokens ?? null) ? (int) $model->max_tokens : null);
        if (is_int($maxTokens) && $maxTokens > 0) {
            $observation['max_tokens_applied'] = $maxTokens;
        }

        $attempt ??= $this->attemptFromCallKey($callKey);
        if ($attempt !== null && $attempt >= 1) {
            $observation['attempt'] = $attempt;
        }

        return array_filter($observation, fn (mixed $value): bool => $value !== null);
    }

    private function resolveEndpoint(AiModel $model): ?ModelEndpoint
    {
        try {
            return ModelEndpointResolver::resolve($model);
        } catch (Throwable $exception) {
            $this->warnSafely($exception);

            return null;
        }
    }

    private function thinkingMode(ModelEndpoint $endpoint, ?CallScene $scene, ?string $requestPayload): ?string
    {
        if (! $scene instanceof CallScene) {
            return $endpoint->thinkingMode;
        }

        // 账本记录"实际下发"的 thinking 形态；声明值仍保留在 ai_models.thinking_mode 上。
        // 同时把请求体粗估长度传入 builder，让 context_length 告警在生产链路真正生效。
        return $this->optionsBuilder->thinkingModeApplied(
            $endpoint,
            $scene,
            $this->optionsBuilder->build(
                endpoint: $endpoint,
                scene: $scene,
                maxTokens: null,
                estimatedInputTokens: $this->estimateInputTokens($endpoint, $requestPayload),
                injectTokenLimit: false,
            ),
        );
    }

    private function estimateInputTokens(ModelEndpoint $endpoint, ?string $requestPayload): ?int
    {
        if ($requestPayload === null || $requestPayload === '' || $endpoint->contextLength === null) {
            return null;
        }

        return $endpoint->estimateTokens($requestPayload);
    }

    /**
     * 仅识别约定后缀（candidate-2 / provider-1 / attempt-3 / :4），
     * 避免把模型 ID 等纯数字尾串误判为尝试序号。
     */
    private function attemptFromCallKey(string $callKey): ?int
    {
        if (preg_match('/(?:attempt|candidate|provider|stage|call)[-_.:]?(\d+)$/i', $callKey, $matches) === 1) {
            return (int) $matches[1];
        }

        return preg_match('/:(\d+)$/', $callKey, $matches) === 1 ? (int) $matches[1] : null;
    }

    private function warnSafely(Throwable $exception): void
    {
        try {
            Log::warning('AI usage telemetry attribution failed safely.', [
                'stage' => 'capture',
                'exception_type' => $exception::class,
            ]);
        } catch (Throwable) {
            // Telemetry and its alert path must remain best effort.
        }
    }
}
