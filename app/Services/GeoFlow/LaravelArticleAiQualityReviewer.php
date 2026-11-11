<?php

namespace App\Services\GeoFlow;

use App\Ai\Adapter\CallBudget;
use App\Ai\Adapter\CallScene;
use App\Ai\Adapter\ModelCallError;
use App\Ai\Adapter\ModelEndpoint;
use App\Ai\Adapter\ModelEndpointResolver;
use App\Ai\Adapter\ProviderOptionsBuilder;
use App\Ai\Agents\ArticleQualityJsonReviewerAgent;
use App\Ai\Agents\ArticleQualityReviewerAgent;
use App\Ai\Agents\LegacyArticleQualityReviewerAgent;
use App\Contracts\ProviderAttemptAwareArticleAiQualityReviewer;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\AiModel;
use App\Services\Admin\AiModelUsageAttempt;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use JsonException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

final readonly class LaravelArticleAiQualityReviewer implements ProviderAttemptAwareArticleAiQualityReviewer
{
    public function __construct(
        private ApiKeyCrypto $apiKeyCrypto,
        private AiUsageQuotaService $usageQuota,
        private ArticleAiQualityProviderCircuitBreaker $circuitBreaker,
        private ArticleAiQualityReadinessRecorder $readinessRecorder,
        private ProviderOptionsBuilder $providerOptionsBuilder,
    ) {}

    public function review(AiModel $model, string $instructions): array
    {
        return $this->reviewWithin(
            $model,
            $instructions,
            (int) config('geoflow.ai_quality_request_timeout_seconds', 160),
        );
    }

    public function reviewWithin(AiModel $model, string $instructions, int $timeoutSeconds): array
    {
        return $this->reviewWithinVersion($model, $instructions, $timeoutSeconds, 'fast_v2');
    }

    public function reviewWithinVersion(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
    ): array {
        if (! in_array($executionVersion, ['legacy', 'fast_v2'], true)) {
            throw new RuntimeException('ai_quality_execution_version_invalid');
        }
        $this->circuitBreaker->beforeRequest($model);
        $reservation = $this->usageQuota->reserveModel($model);
        if ($reservation === null) {
            $exception = new ArticleAiQualityRuntimeException('provider_quota_exhausted');
            $this->circuitBreaker->recordFailure($model, $exception);

            throw $exception;
        }

        return $this->reviewWithReservation(
            $model,
            $instructions,
            $timeoutSeconds,
            $executionVersion,
            $reservation,
        );
    }

    public function reviewWithinVersionTrackingProviderAttempts(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        ArticleAiQualityProviderUsageSession $usageSession,
    ): array {
        if (! in_array($executionVersion, ['legacy', 'fast_v2'], true)) {
            throw new RuntimeException('ai_quality_execution_version_invalid');
        }
        $this->circuitBreaker->beforeRequest($model);
        $reservation = $this->usageQuota->reserveModel($model);
        if ($reservation === null) {
            $exception = new ArticleAiQualityRuntimeException('provider_quota_exhausted');
            $this->circuitBreaker->recordFailure($model, $exception);

            throw $exception;
        }

        return $this->reviewWithReservation(
            $model,
            $instructions,
            $timeoutSeconds,
            $executionVersion,
            $reservation,
            $usageSession,
        );
    }

    public function reviewWithinReservedVersionTrackingProviderAttempts(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        AiUsageReservation $reservation,
        ArticleAiQualityProviderUsageSession $usageSession,
        bool $readinessConfirmed = false,
    ): array {
        if (! in_array($executionVersion, ['legacy', 'fast_v2'], true)) {
            $this->usageQuota->releaseModel($reservation);

            throw new RuntimeException('ai_quality_execution_version_invalid');
        }
        if (! $readinessConfirmed) {
            try {
                $this->circuitBreaker->beforeRequest($model);
            } catch (Throwable $exception) {
                $this->usageQuota->releaseModel($reservation);

                throw $exception;
            }
        }

        return $this->reviewWithReservation(
            $model,
            $instructions,
            $timeoutSeconds,
            $executionVersion,
            $reservation,
            $usageSession,
        );
    }

    public function reviewWithinReservedVersion(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        AiUsageReservation $reservation,
        bool $readinessConfirmed = false,
    ): array {
        if (! in_array($executionVersion, ['legacy', 'fast_v2'], true)) {
            $this->usageQuota->releaseModel($reservation);

            throw new RuntimeException('ai_quality_execution_version_invalid');
        }

        if (! $readinessConfirmed) {
            try {
                $this->circuitBreaker->beforeRequest($model);
            } catch (Throwable $exception) {
                $this->usageQuota->releaseModel($reservation);

                throw $exception;
            }
        }

        return $this->reviewWithReservation(
            $model,
            $instructions,
            $timeoutSeconds,
            $executionVersion,
            $reservation,
        );
    }

    /** @return array<string,mixed> */
    private function reviewWithReservation(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        AiUsageReservation $reservation,
        ?ArticleAiQualityProviderUsageSession $usageSession = null,
    ): array {
        $externalRequestAttempted = false;

        try {
            [$provider, $driver, $baseUrl, $endpoint] = $this->runtimeProvider($model);
            $configuredMaxTokens = (int) config('geoflow.ai_quality_max_output_tokens', 2048);
            $modelMaxTokens = (int) ($model->max_tokens ?: $configuredMaxTokens);
            $maxTokens = max(512, min($configuredMaxTokens, $modelMaxTokens));
            $providerOptions = $this->providerOptionsBuilder->build(
                $endpoint,
                CallScene::ArticleQuality,
                $maxTokens,
                estimatedInputTokens: $endpoint->contextLength !== null
                    ? $endpoint->estimateTokens($instructions)
                    : null,
                baseUrl: $baseUrl,
                injectTokenLimit: false,
            );
            $timeout = max(1, CallBudget::for(
                $endpoint,
                CallScene::ArticleQuality,
                $timeoutSeconds,
            )->total);
            $mode = $this->readinessRecorder->prefersJson($model) ? 'json_fallback' : 'structured';
            $usesV2Schema = $executionVersion === 'fast_v2';
            $attemptStartedAt = hrtime(true);

            if ($mode === 'json_fallback') {
                $providerUsageAttempt = $usageSession?->begin($mode);
                $this->annotateUsageAttempt($providerUsageAttempt, $endpoint, $providerOptions, $maxTokens, 1);
                $providerResponseReturned = false;
                try {
                    $externalRequestAttempted = true;
                    $response = (new ArticleQualityJsonReviewerAgent($instructions, $maxTokens))->withQualityProviderOptions($providerOptions)->prompt(
                        '请执行本分段质检，只返回 JSON。',
                        [],
                        $provider,
                        (string) $model->model_id,
                        $timeout,
                    );
                    $providerResponseReturned = true;
                    $result = $this->decodeResponse($response);
                    if ($providerUsageAttempt !== null) {
                        $usageSession?->providerReturned($providerUsageAttempt, $response->usage);
                    }
                } catch (Throwable $jsonException) {
                    $typed = $this->typedProviderException($jsonException, $baseUrl);
                    if ($providerUsageAttempt !== null) {
                        $providerResponseReturned
                            ? $usageSession?->providerResultDiscarded($providerUsageAttempt, $response->usage ?? null, $typed->safeCode())
                            : $usageSession?->providerFailed($providerUsageAttempt, $typed->safeCode());
                    }
                    $this->recordReadinessAttempt($model, $mode, false, $attemptStartedAt, $typed->safeCode());

                    throw $typed;
                }
            } else {
                $providerUsageAttempt = $usageSession?->begin('structured');
                $this->annotateUsageAttempt($providerUsageAttempt, $endpoint, $providerOptions, $maxTokens, 1);
                $providerResponseReturned = false;
                try {
                    $externalRequestAttempted = true;
                    $agent = $usesV2Schema
                        ? new ArticleQualityReviewerAgent($instructions, $maxTokens)
                        : new LegacyArticleQualityReviewerAgent($instructions, $maxTokens);
                    $response = $agent->withQualityProviderOptions($providerOptions)->prompt(
                        '请执行本分段质检并返回完整结构化结果。',
                        [],
                        $provider,
                        (string) $model->model_id,
                        $timeout,
                    );
                    $providerResponseReturned = true;
                    $result = $this->decodeResponse($response);
                    if ($providerUsageAttempt !== null) {
                        $usageSession?->providerReturned($providerUsageAttempt, $response->usage);
                    }
                } catch (Throwable $structuredException) {
                    $structuredTyped = $this->typedProviderException($structuredException, $baseUrl);
                    if ($providerUsageAttempt !== null) {
                        $providerResponseReturned
                            ? $usageSession?->providerResultDiscarded($providerUsageAttempt, $response->usage ?? null, $structuredTyped->safeCode())
                            : $usageSession?->providerFailed($providerUsageAttempt, $structuredTyped->safeCode());
                    }
                    $this->recordReadinessAttempt($model, 'structured', false, $attemptStartedAt, $structuredTyped->safeCode());
                    $remainingSeconds = $timeout - ((hrtime(true) - $attemptStartedAt) / 1_000_000_000);
                    if ($remainingSeconds < 1 || ! in_array($structuredTyped->safeCode(), [
                        'structured_output_unsupported', 'invalid_model_output',
                    ], true)) {
                        throw $structuredTyped;
                    }
                    $mode = 'json_fallback';
                    $this->usageQuota->recordModelAttempt($reservation);
                    $fallbackReservation = $this->usageQuota->reserveModel($model);
                    if ($fallbackReservation === null) {
                        throw new ArticleAiQualityRuntimeException('provider_quota_exhausted', false, $structuredException);
                    }
                    $reservation = $fallbackReservation;
                    $externalRequestAttempted = false;
                    $attemptStartedAt = hrtime(true);
                    $providerUsageAttempt = $usageSession?->begin($mode);
                    $this->annotateUsageAttempt($providerUsageAttempt, $endpoint, $providerOptions, $maxTokens, 2);
                    $providerResponseReturned = false;
                    try {
                        $externalRequestAttempted = true;
                        $response = (new ArticleQualityJsonReviewerAgent($instructions, $maxTokens))->withQualityProviderOptions($providerOptions)->prompt(
                            '请执行本分段质检，只返回 JSON。',
                            [],
                            $provider,
                            (string) $model->model_id,
                            max(1, (int) floor($remainingSeconds)),
                        );
                        $providerResponseReturned = true;
                        $result = $this->decodeResponse($response);
                        if ($providerUsageAttempt !== null) {
                            $usageSession?->providerReturned($providerUsageAttempt, $response->usage);
                        }
                    } catch (Throwable $fallbackException) {
                        $typed = $this->typedProviderException($fallbackException, $baseUrl, $structuredException);
                        if ($providerUsageAttempt !== null) {
                            $providerResponseReturned
                                ? $usageSession?->providerResultDiscarded($providerUsageAttempt, $response->usage ?? null, $typed->safeCode())
                                : $usageSession?->providerFailed($providerUsageAttempt, $typed->safeCode());
                        }
                        $this->recordReadinessAttempt($model, $mode, false, $attemptStartedAt, $typed->safeCode());

                        throw $typed;
                    }
                }
            }

            $this->usageQuota->recordModelSuccess($reservation);
            $this->circuitBreaker->recordSuccess($model);
            $this->recordReadinessAttempt($model, $mode, true, $attemptStartedAt, null);

            return [
                'result' => $result,
                'usage' => $response->usage->toArray(),
                'model' => [
                    'id' => (int) $model->id,
                    'name' => (string) $model->name,
                    'model_id' => (string) $model->model_id,
                    'provider_driver' => $driver,
                ],
                'mode' => $mode,
            ];
        } catch (Throwable $exception) {
            if ($externalRequestAttempted) {
                $this->usageQuota->recordModelAttempt($reservation);
            } else {
                $this->usageQuota->releaseModel($reservation);
            }

            $typedException = $exception instanceof ArticleAiQualityRuntimeException
                ? $exception
                : $this->typedProviderException($exception, isset($baseUrl) ? $baseUrl : '');
            $this->circuitBreaker->recordFailure($model, $typedException);

            throw $typedException;
        }
    }

    /** @return array{0:string,1:string,2:string,3:ModelEndpoint} */
    private function runtimeProvider(AiModel $model): array
    {
        $endpoint = ModelEndpointResolver::resolve($model);
        $baseUrl = $endpoint->baseUrl;
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($baseUrl === '' || $apiKey === '' || trim((string) $model->model_id) === '') {
            throw new RuntimeException('ai_quality_model_configuration_incomplete');
        }

        $driver = $endpoint->chatDriver();
        $provider = OpenAiRuntimeProvider::registerProvider('article_quality', $driver, $baseUrl, $apiKey);

        return [$provider, $driver, $baseUrl, $endpoint];
    }

    /** @return array<string, mixed> */
    private function decodeResponse(AgentResponse $response): array
    {
        if ($response->steps->last()?->finishReason === FinishReason::Length) {
            throw new ArticleAiQualityRuntimeException('model_output_truncated', true);
        }
        if ($response instanceof StructuredAgentResponse && $response->structured !== []) {
            return $response->structured;
        }

        return $this->decodeJson($response->text);
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $text): array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/\A(?:<think\b[^>]*>.*?<\/think>\s*)+/is', '', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $trimmed) ?? $trimmed;

        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $startsAsJson = str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
            $endsAsJson = str_ends_with($trimmed, '}') || str_ends_with($trimmed, ']');

            throw new ArticleAiQualityRuntimeException(
                $startsAsJson && ! $endsAsJson ? 'model_output_truncated' : 'invalid_model_output',
                true,
                $exception,
            );
        }

        if (! is_array($decoded) || $decoded === []) {
            throw new ArticleAiQualityRuntimeException('invalid_model_output', true);
        }

        return $decoded;
    }

    private function typedProviderException(
        Throwable $exception,
        string $baseUrl,
        ?Throwable $previous = null,
    ): ArticleAiQualityRuntimeException {
        if ($exception instanceof ArticleAiQualityRuntimeException) {
            return $exception;
        }

        $diagnosis = ModelCallError::diagnose($exception, $baseUrl);
        $error = $diagnosis['error'];

        return new ArticleAiQualityRuntimeException(
            $error->legacyQualityCode(),
            $error->retryable(),
            $previous ?? $exception,
            $diagnosis['status'],
            $diagnosis['provider_code'],
            $diagnosis['retry_after'],
        );
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    private function annotateUsageAttempt(
        ?AiModelUsageAttempt $attempt,
        ModelEndpoint $endpoint,
        array $providerOptions,
        int $maxTokens,
        int $attemptNumber,
    ): void {
        if ($attempt === null) {
            return;
        }

        $attempt->context([
            'protocol' => $endpoint->protocol->value,
            'thinking_mode' => $this->providerOptionsBuilder->thinkingModeApplied(
                $endpoint,
                CallScene::ArticleQuality,
                $providerOptions,
            ),
            'max_tokens_applied' => $maxTokens,
            'attempt' => $attemptNumber,
        ]);
    }

    private function recordReadinessAttempt(
        AiModel $model,
        string $mode,
        bool $schemaPassed,
        int $startedAt,
        ?string $errorCode,
    ): void {
        try {
            $this->readinessRecorder->recordAttempt(
                $model,
                $mode,
                $schemaPassed,
                (int) round((hrtime(true) - $startedAt) / 1_000_000),
                $errorCode,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
