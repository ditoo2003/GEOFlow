<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Data\Ai\SystemAiIdentity;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilityPromptTemplates;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySampleRequest;
use App\Services\GeoFlow\AiVisibility\Engine\VisibilityEngineRegistry;
use App\Tenancy\TenantContext;
use RuntimeException;
use Throwable;

/**
 * 多引擎可见性采样入口。
 *
 * 一次调用 = 一个关键词在一个引擎上的一次样本；联网/非联网由 `search_enabled` 显式决定，
 * 引擎不支持关闭检索时直接拒绝，避免把联网结果误标成非联网。
 */
final class VisibilityEngineSampleService
{
    public function __construct(
        private readonly VisibilityEngineRegistry $engines,
        private readonly AiVisibilityConfigurationResolver $configuration,
        private readonly VisibilitySamplePersister $persister,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{
     *     prompt_template_key?:string,
     *     search_enabled?:bool,
     *     region?:string,
     *     locale?:string,
     *     sample_index?:int,
     *     entities?:list<array{entity_type?:string,entity_key:string,canonical_name:string,aliases?:list<string>}>
     * }  $options
     */
    public function collect(
        SystemAiIdentity $identity,
        string $engineKey,
        string $keyword,
        array $options = [],
    ): AiVisibilityRun {
        $identity->assertCanCollectVisibility();

        $keyword = trim($keyword);
        if ($keyword === '') {
            throw new RuntimeException('ai_visibility_keyword_empty');
        }

        $adapter = $this->engines->for($engineKey);
        $searchEnabled = (bool) ($options['search_enabled'] ?? true);
        if (! $adapter->supportsSearchToggle() && ! $searchEnabled) {
            throw new RuntimeException('ai_visibility_search_toggle_unsupported');
        }

        $model = $this->configuration->engineModel($identity, $adapter->key());
        if (! $model instanceof AiModel) {
            throw new RuntimeException('ai_model_unavailable');
        }

        $request = VisibilitySampleRequest::forKeyword(
            keyword: $keyword,
            promptTemplateKey: (string) ($options['prompt_template_key'] ?? VisibilityPromptTemplates::defaultKey()),
            searchEnabled: $searchEnabled,
            region: (string) ($options['region'] ?? 'global'),
            locale: (string) ($options['locale'] ?? 'zh_CN'),
            sampleIndex: isset($options['sample_index']) ? (int) $options['sample_index'] : null,
        );

        $tenantId = $this->tenants->tenantIdOrDefault();
        $batch = $this->persister->resolveBatch($request, $adapter->key(), $adapter->variant(), $tenantId);
        $run = $this->persister->startRun(
            batch: $batch,
            request: $request,
            engineKey: $adapter->key(),
            engineVariant: $adapter->variant(),
            model: $model,
            tenantId: $tenantId,
        );

        try {
            $sample = $adapter->sample($model, $request);
        } catch (Throwable $exception) {
            $errorCode = $this->errorCode($exception);
            $this->persister->fail($run, $batch, $errorCode);

            throw new RuntimeException($errorCode);
        }

        return $this->persister->complete($run, $batch, $sample, (array) ($options['entities'] ?? []));
    }

    private function errorCode(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if (preg_match('/\A[a-z0-9_.:-]{1,100}\z/', $message) === 1) {
            return $message;
        }
        if (preg_match('/(?:HTTP\s*)?(?:401|403)\b/i', $message) === 1) {
            return 'ai_provider_auth_failed';
        }

        return 'ai_provider_request_failed';
    }
}
