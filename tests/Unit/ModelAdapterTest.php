<?php

namespace Tests\Unit;

use App\Ai\Adapter\CallBudget;
use App\Ai\Adapter\CallScene;
use App\Ai\Adapter\Capability;
use App\Ai\Adapter\ModelCallError;
use App\Ai\Adapter\ModelEndpointResolver;
use App\Ai\Adapter\Protocol;
use App\Ai\Adapter\ProviderOptionsBuilder;
use App\Ai\Adapter\TimeoutChainValidator;
use Tests\TestCase;

class ModelAdapterTest extends TestCase
{
    public function test_chat_and_embedding_base_urls_match_legacy_rules(): void
    {
        $this->assertSame(
            'https://ark.cn-beijing.volces.com/api/v3',
            ModelEndpointResolver::chatBaseUrl('https://ark.cn-beijing.volces.com/api/v3/chat/completions'),
        );
        $this->assertSame(
            'https://api.openai.com/v1',
            ModelEndpointResolver::chatBaseUrl('https://api.openai.com/v1/responses'),
        );
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta',
            ModelEndpointResolver::chatBaseUrl('https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent'),
        );
        $this->assertSame(
            'https://quality.example.test/v1',
            ModelEndpointResolver::chatBaseUrl('https://quality.example.test'),
        );
        $this->assertSame(
            'https://ark.cn-beijing.volces.com/api/v3',
            ModelEndpointResolver::embeddingBaseUrl('https://ark.cn-beijing.volces.com/api/v3/embeddings/multimodal'),
        );
    }

    public function test_opaque_ark_endpoint_derives_multimodal_protocol_from_model_and_url(): void
    {
        $byModel = ModelEndpointResolver::fromParts(
            'https://ark.cn-beijing.volces.com/api/v3',
            'doubao-embedding-vision-250615',
            'embedding',
        );
        $this->assertSame(Protocol::OpenAiMultimodalEmbedding, $byModel->protocol);
        $this->assertSame('/embeddings/multimodal', $byModel->protocol->requestSuffix());
        $this->assertTrue($byModel->hasCapability(Capability::MultimodalEmbedding));

        $byExplicitUrl = ModelEndpointResolver::fromParts(
            'https://ark.cn-beijing.volces.com/api/v3/embeddings/multimodal',
            'ep-20260101-abc',
            'embedding',
        );
        $this->assertTrue($byExplicitUrl->isMultimodalEmbedding());

        $textOnly = ModelEndpointResolver::fromParts(
            'https://ark.cn-beijing.volces.com/api/v3',
            'ep-20260101-abc',
            'embedding',
        );
        $this->assertSame(Protocol::OpenAiEmbedding, $textOnly->protocol);
        $this->assertSame('https://ark.cn-beijing.volces.com/api/v3/embeddings', $textOnly->requestEndpoint());
    }

    public function test_explicit_protocol_declaration_overrides_derivation(): void
    {
        $endpoint = ModelEndpointResolver::fromParts(
            'https://ark.cn-beijing.volces.com/api/v3',
            'ep-20260101-abc',
            'embedding',
            protocol: Protocol::OpenAiMultimodalEmbedding,
        );

        $this->assertTrue($endpoint->isMultimodalEmbedding());
        $this->assertSame('/embeddings/multimodal', $endpoint->protocol->requestSuffix());
    }

    public function test_ark_thinking_is_disabled_for_quality_but_not_for_generation(): void
    {
        $endpoint = ModelEndpointResolver::fromParts(
            'https://ark.cn-beijing.volces.com/api/v3',
            'ep-20260101-abc',
            'chat',
        );
        $builder = new ProviderOptionsBuilder;

        $quality = $builder->build($endpoint, CallScene::ArticleQuality, 2048, injectTokenLimit: false);
        $this->assertSame(['type' => 'disabled'], $quality['thinking']);
        $this->assertSame('disabled', $builder->thinkingModeApplied($endpoint, CallScene::ArticleQuality, $quality));

        $generation = $builder->build($endpoint, CallScene::ContentGeneration, 4096);
        $this->assertArrayNotHasKey('thinking', $generation);
        $this->assertSame(4096, $generation['max_tokens']);
    }

    public function test_deepseek_minimax_and_glm_keep_their_quality_contracts(): void
    {
        $builder = new ProviderOptionsBuilder;
        $deepseek = ModelEndpointResolver::fromParts('https://api.deepseek.com/v1', 'deepseek-chat', 'chat');
        $minimax = ModelEndpointResolver::fromParts('https://api.minimaxi.com/v1', 'minimax-m2', 'chat');
        $glm = ModelEndpointResolver::fromParts('https://open.bigmodel.cn/api/paas/v4', 'glm-4.5', 'chat');

        $this->assertSame(['type' => 'disabled'], $builder->build($deepseek, CallScene::ArticleQuality, 2048)['thinking']);
        $minimaxOptions = $builder->build($minimax, CallScene::ArticleQuality, 2048);
        $this->assertTrue($minimaxOptions['reasoning_split']);
        $this->assertArrayNotHasKey('thinking', $minimaxOptions);
        $glmOptions = $builder->build($glm, CallScene::ArticleQuality, 2048);
        $this->assertSame(['type' => 'disabled'], $glmOptions['thinking']);
        $this->assertSame(['type' => 'json_object'], $glmOptions['response_format']);
    }

    public function test_context_length_exceeding_input_only_warns_and_keeps_token_limit(): void
    {
        $endpoint = ModelEndpointResolver::fromParts(
            'https://ark.cn-beijing.volces.com/api/v3',
            'ep-20260101-abc',
            'chat',
            contextLength: 100,
        );

        $options = (new ProviderOptionsBuilder)->build(
            $endpoint,
            CallScene::ArticleQuality,
            2048,
            estimatedInputTokens: 400,
        );

        $this->assertSame(2048, $options['max_tokens']);
        $this->assertArrayHasKey('thinking', $options);
    }

    public function test_error_normalization_maps_status_and_protocol_errors(): void
    {
        $this->assertSame(ModelCallError::RateLimited, ModelCallError::fromStatus(429));
        $this->assertSame('provider_rate_limited', ModelCallError::RateLimited->legacyQualityCode());
        $this->assertSame(
            ModelCallError::UnsupportedCapability,
            ModelCallError::fromStatus(400, 'The model does not support this api.'),
        );
        $this->assertSame('context_length_exceeded', ModelCallError::ContextLengthExceeded->value);
        $this->assertStringContainsString('上下文上限', ModelCallError::ContextLengthExceeded->message([
            'estimated_tokens' => 9000,
            'context_length' => 8192,
        ]));
    }

    public function test_call_budget_takes_minimum_and_reports_violations(): void
    {
        $endpoint = ModelEndpointResolver::fromParts(
            'https://ark.cn-beijing.volces.com/api/v3',
            'ep-20260101-abc',
            'chat',
            timeoutProfile: ['connect' => 5, 'read' => 60, 'total' => 90],
        );

        $budget = CallBudget::for($endpoint, CallScene::ArticleQuality, 300, remainingBusinessSeconds: 360);

        $this->assertSame(90, $budget->total);
        $this->assertSame(60, $budget->read);
        $this->assertSame(5, $budget->connect);
        $this->assertNull((new TimeoutChainValidator)->budgetViolation($budget));
    }

    public function test_timeout_chain_violations_are_reported(): void
    {
        $validator = new TimeoutChainValidator;

        $this->assertSame([], $validator->violations([
            'business' => 100,
            'job' => 200,
            'worker' => 300,
            'retry_after' => 400,
        ]));
        $this->assertNotSame([], $validator->violations([
            'business' => 300,
            'job' => 200,
            'worker' => 100,
            'retry_after' => 50,
        ]));
    }

    public function test_generation_and_optimization_chains_share_the_same_ordering_rule(): void
    {
        $validator = new TimeoutChainValidator;

        $generation = $validator->generationChain();
        $this->assertSame(420, $generation['business']);
        $this->assertSame(480, $generation['job']);
        $this->assertSame(930, $generation['worker']);
        $this->assertSame([], $validator->violations($generation));

        $optimization = $validator->optimizationChain();
        $this->assertSame(850, $optimization['job']);
        $this->assertSame(900, $optimization['worker']);
        $this->assertSame([], $validator->violations($optimization));

        config()->set('geoflow.content_worker_timeout_seconds', 400);
        $this->assertNotSame([], $validator->violations($validator->generationChain()));
    }

    public function test_content_generation_budget_reads_the_shared_config_source(): void
    {
        config()->set('geoflow.content_provider_timeout_seconds', 260);

        $endpoint = ModelEndpointResolver::fromParts(
            'https://ark.cn-beijing.volces.com/api/v3',
            'ep-20260101-abc',
            'chat',
        );
        $budget = CallBudget::for($endpoint, CallScene::ContentGeneration);

        $this->assertSame(260, $budget->total);
        $this->assertSame(260, $budget->read);
    }
}
