<?php

namespace Tests\Feature;

use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Models\AiVisibilitySampleBatch;
use App\Models\SiteSetting;
use App\Services\GeoFlow\AiVisibility\AiProviderEndpointPolicy;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\VisibilityEngineSampleService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class VisibilityEngineSampleServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITIES = [
        [
            'entity_type' => 'brand',
            'entity_key' => 'geoflow',
            'canonical_name' => 'GEOFlow',
            'aliases' => ['GEOFlow'],
        ],
        [
            'entity_type' => 'competitor',
            'entity_key' => 'rival',
            'canonical_name' => 'RivalAI',
            'aliases' => ['RivalAI'],
        ],
    ];

    public function test_qwen_sample_persists_the_engine_contract_and_mentions(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => '首选 GEOFlow，其次是 RivalAI。',
                        'annotations' => [[
                            'type' => 'url',
                            'url' => 'https://example.com/qwen-source',
                            'title' => 'Qwen Source',
                        ]],
                    ],
                ]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 30],
            ]),
        ]);

        $this->bindEngineModel('qwen', 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'qwen-max');

        $run = $this->collect('qwen', 'AI 搜索推荐', ['entities' => self::ENTITIES]);

        $this->assertSame(AiVisibilityRun::PROVIDER_ENGINE_SAMPLE, $run->provider_type);
        $this->assertSame('qwen', $run->engine_key);
        $this->assertSame('api', $run->collection_method);
        $this->assertTrue($run->search_enabled);
        $this->assertFalse($run->is_legacy);
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('首选 GEOFlow，其次是 RivalAI。', $run->answer_text);
        $this->assertNotNull($run->sample_batch_id);
        $this->assertSame(1, $run->sources()->count());
        $this->assertSame(2, $run->mentions()->count());
        $this->assertDatabaseHas('ai_visibility_mentions', [
            'ai_visibility_run_id' => (int) $run->id,
            'entity_key' => 'geoflow',
            'order_index' => 1,
        ]);
        $this->assertDatabaseHas('ai_visibility_sources', [
            'ai_visibility_run_id' => (int) $run->id,
            'citation_kind' => 'inline',
            'url' => 'https://example.com/qwen-source',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions'
            && ($request['enable_search'] ?? null) === true
            && ($request['search_options']['forced_search'] ?? null) === true);
    }

    public function test_online_and_offline_samples_never_share_a_batch(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'GEOFlow 回答']]],
            ]),
        ]);

        $this->bindEngineModel('qwen', 'https://dashscope.aliyuncs.com/compatible-mode/v1', 'qwen-max');

        $online = $this->collect('qwen', 'AI 搜索', ['search_enabled' => true]);
        $offline = $this->collect('qwen', 'AI 搜索', ['search_enabled' => false]);

        $this->assertNotSame($online->sample_batch_id, $offline->sample_batch_id);
        $this->assertSame(2, AiVisibilitySampleBatch::query()->count());
        $this->assertTrue((bool) AiVisibilitySampleBatch::query()->where('id', $online->sample_batch_id)->value('search_enabled'));
        $this->assertFalse((bool) AiVisibilitySampleBatch::query()->where('id', $offline->sample_batch_id)->value('search_enabled'));
        $this->assertSame(1, AiVisibilityRun::query()->where('search_enabled', true)->count());
        $this->assertSame(1, AiVisibilityRun::query()->where('search_enabled', false)->count());

        Http::assertSent(fn ($request): bool => ! array_key_exists('enable_search', $request->data()));
    }

    public function test_kimi_sample_uses_the_builtin_web_search_tool_and_parses_annotations(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.moonshot.cn/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => 'Kimi 回答',
                        'annotations' => [[
                            'type' => 'url_citation',
                            'url' => 'https://example.com/kimi-source',
                            'title' => 'Kimi Source',
                        ]],
                    ],
                ]],
            ]),
        ]);

        $this->bindEngineModel('kimi', 'https://api.moonshot.cn/v1', 'kimi-k3');

        $run = $this->collect('kimi', 'AI 搜索');

        $this->assertSame('kimi', $run->engine_key);
        $this->assertSame(1, $run->sources()->count());
        $this->assertDatabaseHas('ai_visibility_sources', [
            'ai_visibility_run_id' => (int) $run->id,
            'domain' => 'example.com',
        ]);

        Http::assertSent(fn ($request): bool => ($request['tools'][0]['function']['name'] ?? null) === '$web_search'
            && ($request['max_completion_tokens'] ?? null) > 0
            && ! array_key_exists('temperature', $request->data()));
    }

    public function test_kimi_builtin_search_completes_the_tool_round_trip_before_answering(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.moonshot.cn/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => [
                            'role' => 'assistant',
                            'content' => '',
                            'tool_calls' => [[
                                'id' => 't-web_search-1',
                                'type' => 'builtin_function',
                                'function' => [
                                    'name' => '$web_search',
                                    'arguments' => '{"search_result":{"search_id":"s1"},"usage":{"total_tokens":900}}',
                                ],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25],
                ])
                ->push([
                    'choices' => [[
                        'finish_reason' => 'stop',
                        'message' => [
                            'content' => 'Kimi 联网回答',
                            'annotations' => [[
                                'type' => 'url_citation',
                                'url' => 'https://example.com/kimi-web',
                                'title' => 'Kimi Web',
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 60, 'total_tokens' => 960],
                ]),
        ]);

        $this->bindEngineModel('kimi', 'https://api.moonshot.cn/v1', 'kimi-k2-0905-preview');

        $run = $this->collect('kimi', 'AI 搜索');

        $this->assertSame('Kimi 联网回答', $run->answer_text);
        $this->assertSame(1, $run->sources()->count());
        $this->assertSame(
            ['prompt_tokens' => 920, 'completion_tokens' => 65, 'total_tokens' => 985],
            $run->usage_json,
        );
        $this->assertCount(2, (array) ($run->raw_response_json['rounds'] ?? []));

        Http::assertSent(function ($request): bool {
            $tool = collect((array) $request['messages'])->first(
                static fn ($message): bool => is_array($message) && ($message['role'] ?? null) === 'tool',
            );

            return is_array($tool)
                && ($tool['tool_call_id'] ?? null) === 't-web_search-1'
                && str_contains((string) ($tool['content'] ?? ''), 'search_id');
        });
    }

    public function test_perplexity_rejects_offline_sampling_instead_of_mislabelling(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->bindEngineModel('perplexity', 'https://api.perplexity.ai', 'sonar-pro');

        try {
            $this->collect('perplexity', 'AI 搜索', ['search_enabled' => false]);
            $this->fail('Perplexity 关闭联网时应拒绝采样');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_visibility_search_toggle_unsupported', $exception->getMessage());
        }

        $this->assertSame(0, AiVisibilityRun::query()->count());
        $this->assertSame(0, AiVisibilitySampleBatch::query()->count());

        Http::assertNothingSent();
    }

    public function test_perplexity_merges_citation_lists_and_search_results(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.perplexity.ai/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Perplexity 回答']]],
                'citations' => ['https://example.com/p1'],
                'search_results' => [
                    ['title' => 'P1', 'url' => 'https://example.com/p1', 'date' => '2026-01-02'],
                    ['title' => 'P2', 'url' => 'https://example.com/p2'],
                ],
            ]),
        ]);

        $this->bindEngineModel('perplexity', 'https://api.perplexity.ai', 'sonar-pro');

        $run = $this->collect('perplexity', 'AI 搜索');

        $this->assertSame(2, $run->sources()->count());
        $this->assertDatabaseHas('ai_visibility_sources', [
            'ai_visibility_run_id' => (int) $run->id,
            'url' => 'https://example.com/p2',
            'citation_kind' => 'search_result',
        ]);
    }

    public function test_chatgpt_sample_parses_responses_url_citations(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_chatgpt_1',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'ChatGPT 回答',
                        'annotations' => [[
                            'type' => 'url_citation',
                            'url' => 'https://example.com/chatgpt-source',
                            'title' => 'ChatGPT Source',
                        ]],
                    ]],
                ]],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 9],
            ]),
        ]);

        $this->bindEngineModel('chatgpt', 'https://api.openai.com/v1', 'gpt-5-search');

        $run = $this->collect('chatgpt', 'AI 搜索');

        $this->assertSame('ChatGPT 回答', $run->answer_text);
        $this->assertSame(1, $run->sources()->count());
        $this->assertSame(['input_tokens' => 3, 'output_tokens' => 9], $run->usage_json);

        Http::assertSent(fn ($request): bool => ($request['tools'][0]['type'] ?? null) === 'web_search');
    }

    public function test_doubao_offline_sample_omits_the_web_search_tool(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'id' => 'resp_doubao_1',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '豆包离线回答',
                        'annotations' => [],
                    ]],
                ]],
            ]),
        ]);

        $this->bindEngineModel('doubao', 'https://ark.cn-beijing.volces.com/api/v3', 'doubao-seed-2-0-lite');

        $run = $this->collect('doubao', 'AI 搜索', ['search_enabled' => false]);

        $this->assertFalse($run->search_enabled);
        $this->assertSame('doubao', $run->engine_key);
        $this->assertSame(0, $run->sources()->count());

        Http::assertSent(fn ($request): bool => ! array_key_exists('tools', $request->data()));
    }

    public function test_unregistered_engine_is_rejected(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        try {
            $this->collect('gemini', 'AI 搜索');
            $this->fail('Gemini 在 P1，不应被 P0 注册表接受');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_visibility_engine_unsupported', $exception->getMessage());
        }

        $this->assertSame(0, AiVisibilityRun::query()->count());
    }

    public function test_endpoint_policy_only_trusts_https_engine_hosts(): void
    {
        $policy = app(AiProviderEndpointPolicy::class);

        $this->assertTrue($policy->acceptsModelApi('qwen', 'https://dashscope.aliyuncs.com/compatible-mode/v1'));
        $this->assertTrue($policy->acceptsModelApi('kimi', 'https://api.moonshot.cn/v1'));
        $this->assertTrue($policy->acceptsModelApi('perplexity', 'https://api.perplexity.ai'));
        $this->assertTrue($policy->acceptsModelApi('chatgpt', 'https://api.openai.com/v1'));
        $this->assertFalse($policy->acceptsModelApi('qwen', 'http://dashscope.aliyuncs.com/compatible-mode/v1'));
        $this->assertFalse($policy->acceptsModelApi('chatgpt', 'https://api.openai.com.evil.example/v1'));
    }

    /** @param array<string,mixed> $options */
    private function collect(string $engineKey, string $keyword, array $options = []): AiVisibilityRun
    {
        return app(VisibilityEngineSampleService::class)->collect(
            SystemAiIdentity::visibilityCollection(),
            $engineKey,
            $keyword,
            $options,
        );
    }

    private function bindEngineModel(string $engineKey, string $apiUrl, string $modelId): AiModel
    {
        $owner = Admin::query()->create([
            'username' => 'engine-owner-'.uniqid(),
            'display_name' => 'Engine Owner',
            'password' => 'secret',
            'role' => 'super_admin',
            'status' => 'active',
            'ai_config_access_version' => 1,
        ]);

        $model = new AiModel([
            'name' => $engineKey.' engine model',
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-engine-key'),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => $apiUrl,
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ])->save();

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => AiVisibilityConfigurationResolver::ENGINE_MODEL_SETTING_PREFIX.$engineKey],
            ['setting_value' => (string) $model->id],
        );

        return $model;
    }
}
