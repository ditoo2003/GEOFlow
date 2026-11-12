<?php

namespace Tests\Feature;

use App\Ai\Adapter\ModelEndpointResolver;
use App\Ai\Adapter\Protocol;
use App\Models\Admin;
use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdminAiModelCapabilityDeclarationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_multimodal_embedding_with_only_a_base_url(): void
    {
        $admin = $this->createAdmin();
        $baseUrl = 'https://ark.cn-beijing.volces.com/api/v3';

        $this->actingAs($admin, 'admin')
            ->post(route('admin.ai-models.store'), $this->payload([
                'model_type' => 'embedding',
                'model_id' => 'ep-opaque',
                'api_url' => $baseUrl,
                'provider_key' => 'ark',
                'protocol' => '',
                'capabilities' => ['multimodal_embedding'],
                'context_length' => 32768,
                'thinking_mode' => 'disabled',
                'timeout_profile' => ['connect' => 8, 'read' => 120, 'total' => 180],
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $model = AiModel::query()->where('model_id', 'ep-opaque')->sole();

        $this->assertSame('ark', $model->provider_key);
        $this->assertNull($model->protocol);
        $this->assertSame(['multimodal_embedding'], $model->capabilities);
        $this->assertSame(32768, $model->context_length);
        $this->assertSame('disabled', $model->thinking_mode);
        $this->assertSame(['connect' => 8, 'read' => 120, 'total' => 180], $model->timeout_profile);

        $endpoint = ModelEndpointResolver::resolve($model);
        $this->assertSame(Protocol::OpenAiMultimodalEmbedding, $endpoint->protocol);
        $this->assertSame($baseUrl.'/embeddings/multimodal', $endpoint->requestEndpoint());
    }

    public function test_multimodal_embedding_capability_rejects_chat_model_type(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.ai-models.create'))
            ->post(route('admin.ai-models.store'), $this->payload([
                'model_type' => 'chat',
                'capabilities' => ['multimodal_embedding'],
            ]))
            ->assertRedirect(route('admin.ai-models.create'))
            ->assertSessionHasErrors([
                'capabilities' => __('admin.ai_models.error.capability_multimodal_requires_embedding'),
            ]);
    }

    public function test_explicit_multimodal_protocol_is_used_by_the_connection_test_for_an_opaque_endpoint(): void
    {
        Http::preventStrayRequests();
        $endpoint = 'https://ark.cn-beijing.volces.com/api/v3/embeddings/multimodal';
        Http::fake([$endpoint => Http::response([
            'data' => ['object' => 'embedding', 'embedding' => [0.1, 0.2, 0.3]],
        ])]);

        $admin = $this->createAdmin();
        $model = $this->createModel($admin, [
            'model_type' => 'embedding',
            'model_id' => 'ep-opaque',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'protocol' => Protocol::OpenAiMultimodalEmbedding->value,
            'capabilities' => ['multimodal_embedding'],
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.endpoint', $endpoint);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['input'] === [
            ['type' => 'text', 'text' => 'GEOFlow embedding connection test'],
        ]);
    }

    public function test_connection_test_translates_unsupported_protocol_into_readable_chinese(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/embeddings' => Http::response([
                'error' => [
                    'code' => 'InvalidParameter',
                    'message' => 'The model ep-opaque does not support this api.',
                ],
            ], 400),
        ]);

        $admin = $this->createAdmin();
        $model = $this->createModel($admin, [
            'model_type' => 'embedding',
            'model_id' => 'ep-opaque',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'protocol' => Protocol::OpenAiEmbedding->value,
            'capabilities' => ['embedding'],
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-models.test', ['modelId' => $model->id]))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('meta.error_code', 'unsupported_capability');

        $this->assertStringContainsString('多模态 Embeddings', (string) $response->json('message'));
    }

    public function test_updating_a_legacy_model_keeps_capability_columns_null_and_behaviour_unchanged(): void
    {
        $admin = $this->createAdmin();
        $model = $this->createModel($admin, [
            'model_type' => 'embedding',
            'model_id' => 'legacy-embedding',
            'api_url' => 'https://ai.example.test/v1',
        ]);

        $this->assertNull($model->provider_key);
        $this->assertNull($model->capabilities);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.ai-models.update', ['modelId' => $model->id]), $this->payload([
                'model_type' => 'embedding',
                'model_id' => 'legacy-embedding',
                'api_url' => 'https://ai.example.test/v1',
            ]))
            ->assertRedirect(route('admin.ai-models.index'));

        $fresh = $model->fresh();
        $this->assertNull($fresh->provider_key);
        $this->assertNull($fresh->protocol);
        $this->assertNull($fresh->capabilities);
        $this->assertNull($fresh->context_length);
        $this->assertNull($fresh->timeout_profile);
        $this->assertNull($fresh->thinking_mode);
        $this->assertSame(Protocol::OpenAiEmbedding, ModelEndpointResolver::resolve($fresh)->protocol);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'capability-admin',
            'password' => 'secret-123',
            'email' => 'capability-admin@example.test',
            'display_name' => 'Capability Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Capability Model',
            'version' => 'v1',
            'api_key' => 'test-api-key',
            'model_id' => 'capability-model',
            'model_type' => 'chat',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'failover_priority' => 20,
            'daily_limit' => 0,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createModel(Admin $owner, array $overrides = []): AiModel
    {
        $model = new AiModel(array_merge([
            'name' => 'Capability Model',
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'capability-model',
            'model_type' => 'chat',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'status' => 'active',
        ], $overrides));
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model->refresh();
    }
}
