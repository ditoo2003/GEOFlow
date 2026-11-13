<?php

namespace App\Services\GeoFlow\AiVisibility\Engine;

use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilityCitation;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySample;
use App\Services\GeoFlow\AiVisibility\Contract\VisibilitySampleRequest;
use App\Services\GeoFlow\AiVisibility\DoubaoArkResponsesClient;
use App\Services\GeoFlow\AiVisibility\Engine\Support\VisibilityCitationFactory;
use Carbon\CarbonImmutable;

/**
 * 豆包（火山方舟 Responses + web_search 工具）。
 *
 * 复用历史 Ark 客户端，但显式区分联网/非联网：`search_enabled = false` 时不带 web_search 工具。
 */
final class DoubaoEngineAdapter implements VisibilityEngineAdapter
{
    public function __construct(
        private readonly DoubaoArkResponsesClient $client,
    ) {}

    public function key(): string
    {
        return AiVisibilityRun::ENGINE_DOUBAO;
    }

    public function variant(): string
    {
        return AiVisibilityRun::VARIANT_API;
    }

    public function supportsSearchToggle(): bool
    {
        return true;
    }

    public function supportsCitations(): bool
    {
        return true;
    }

    public function sample(AiModel $model, VisibilitySampleRequest $request): VisibilitySample
    {
        $result = $this->client->answerWithWebSearch($model, $request->promptText, [
            'temperature' => $request->temperature,
            'max_output_tokens' => $request->maxOutputTokens,
            'web_search' => $request->searchEnabled,
        ]);

        $citations = [];
        foreach ($result->sources as $source) {
            $citations[] = new VisibilityCitation(
                index: 0,
                kind: $source->sourceType === 'native_annotation'
                    ? VisibilityCitation::KIND_INLINE
                    : VisibilityCitation::KIND_SEARCH_RESULT,
                url: $source->url,
                domain: $source->domain,
                title: $source->title,
                snippet: $source->snippet ?? $source->summary,
                publishedAt: $source->publishedAt instanceof CarbonImmutable
                    ? $source->publishedAt
                    : ($source->publishedAt !== null ? CarbonImmutable::instance($source->publishedAt) : null),
                rank: $source->rank,
            );
        }

        return new VisibilitySample(
            engineKey: $this->key(),
            engineVariant: $this->variant(),
            collectionMethod: AiVisibilityRun::METHOD_API,
            modelKey: (string) ($model->model_id ?? ''),
            request: $request,
            status: VisibilitySample::STATUS_COMPLETED,
            answerText: $result->answerText,
            citations: VisibilityCitationFactory::merge($citations),
            usage: $result->usage,
            rawRequest: $result->rawRequest,
            rawResponse: $result->rawResponse,
            latencyMs: $result->latencyMs,
            httpStatus: 200,
            sampledAt: CarbonImmutable::now(),
        );
    }
}
