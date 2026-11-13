<?php

namespace Tests\Feature;

use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use App\Models\Tenant;
use App\Models\TenantQuotaAlert;
use App\Models\TenantUsageEvent;
use App\Services\Billing\VisibilitySampleUsageMeter;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\VisibilityEngineSampleService;
use App\Services\GeoFlow\AiVisibility\VisibilitySamplingDeferredException;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VisibilitySampleQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_completed_sample_is_metered_exactly_once(): void
    {
        $this->fakeQwen();

        $run = $this->collectQwen();
        $tenantId = app(TenantContext::class)->tenantIdOrDefault();

        $this->assertDatabaseHas('tenant_usage_events', [
            'tenant_id' => $tenantId,
            'meter' => TenantUsageEvent::METER_VISIBILITY_SAMPLES,
            'idempotency_key' => 'visibility.sample:'.$run->uuid,
            'quantity' => 1,
        ]);

        $this->assertFalse(app(VisibilitySampleUsageMeter::class)->record($run, $tenantId));
        $this->assertSame(1, DB::table('tenant_usage_events')->where('meter', TenantUsageEvent::METER_VISIBILITY_SAMPLES)->count());
    }

    public function test_exhausted_budget_degrades_frequency_instead_of_hard_rejecting(): void
    {
        $this->fakeQwen();
        $tenantId = $this->overrideVisibilityQuota(1);

        $first = $this->collectQwen();
        $this->assertSame(1, AiVisibilityRun::query()->count());

        $this->assertDatabaseHas('tenant_quota_alerts', [
            'tenant_id' => $tenantId,
            'meter' => TenantQuotaAlert::METER_VISIBILITY_SAMPLES,
            'level' => TenantQuotaAlert::LEVEL_SATURATED,
        ]);

        try {
            $this->collectQwen();
            $this->fail('预算用尽后当日第二个样本应降频跳过');
        } catch (VisibilitySamplingDeferredException $exception) {
            $this->assertSame('budget_exhausted', $exception->reason);
            $this->assertSame('ai_visibility_sampling_deferred', $exception->getMessage());
        }

        $this->assertSame(1, AiVisibilityRun::query()->count());
        Http::assertSentCount(1);
        $this->assertNotNull($first->raw_pruned_at === null);
    }

    public function test_crossing_eighty_percent_raises_degrade_alert_and_keeps_one_sample_per_day(): void
    {
        $this->fakeQwen();
        $tenantId = $this->overrideVisibilityQuota(10);
        $this->seedUsage($tenantId, 8);

        $this->collectQwen();

        $this->assertDatabaseHas('tenant_quota_alerts', [
            'tenant_id' => $tenantId,
            'meter' => TenantQuotaAlert::METER_VISIBILITY_SAMPLES,
            'level' => TenantQuotaAlert::LEVEL_DEGRADE,
        ]);

        try {
            $this->collectQwen();
            $this->fail('降频后同一批次当日只能采 1 次');
        } catch (VisibilitySamplingDeferredException $exception) {
            $this->assertSame('budget_degraded', $exception->reason);
        }

        Http::assertSentCount(1);
    }

    public function test_prune_command_redacts_raw_payloads_beyond_retention(): void
    {
        $old = $this->seedRun(now()->subDays(91));
        $recent = $this->seedRun(now()->subHours(2));

        $this->artisan('geoflow:prune-ai-visibility-raw', ['--days' => 90])->assertExitCode(0);

        $old->refresh();
        $recent->refresh();

        $this->assertNull($old->answer_text);
        $this->assertNull($old->raw_request_json);
        $this->assertNull($old->raw_response_json);
        $this->assertNotNull($old->raw_pruned_at);
        $this->assertSame(1, $old->sources()->count());

        $this->assertSame('仍在留存期内', $recent->answer_text);
        $this->assertNull($recent->raw_pruned_at);

        $dryRun = $this->seedRun(now()->subDays(120));
        $this->artisan('geoflow:prune-ai-visibility-raw', ['--days' => 90, '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame('仍在留存期内', $dryRun->refresh()->answer_text);
    }

    private function fakeQwen(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'GEOFlow 回答']]],
            ]),
        ]);

        $owner = Admin::query()->create([
            'username' => 'quota-owner-'.uniqid(),
            'display_name' => 'Quota Owner',
            'password' => 'secret',
            'role' => 'super_admin',
            'status' => 'active',
            'ai_config_access_version' => 1,
        ]);

        $model = new AiModel([
            'name' => 'qwen quota model',
            'version' => 'v1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-engine-key'),
            'model_id' => 'qwen-max',
            'model_type' => 'chat',
            'api_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
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
            ['setting_key' => AiVisibilityConfigurationResolver::ENGINE_MODEL_SETTING_PREFIX.'qwen'],
            ['setting_value' => (string) $model->id],
        );
    }

    private function collectQwen(): AiVisibilityRun
    {
        return app(VisibilityEngineSampleService::class)->collect(
            SystemAiIdentity::visibilityCollection(),
            AiVisibilityRun::ENGINE_QWEN,
            'AI 搜索推荐',
        );
    }

    private function overrideVisibilityQuota(int $quota): int
    {
        $tenantId = app(TenantContext::class)->tenantIdOrDefault();
        $tenant = Tenant::query()->findOrFail($tenantId);
        $settings = (array) ($tenant->settings ?? []);
        $settings['quota'] = array_merge((array) ($settings['quota'] ?? []), ['visibility_sample_quota' => $quota]);
        $tenant->update(['settings' => $settings]);

        return $tenantId;
    }

    private function seedUsage(int $tenantId, int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            DB::table('tenant_usage_events')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'meter' => TenantUsageEvent::METER_VISIBILITY_SAMPLES,
                'quantity' => 1,
                'source_type' => 'ai_visibility_run',
                'source_id' => (string) $index,
                'idempotency_key' => 'visibility.sample:seed-'.$index,
                'period' => now(config('app.timezone'))->format('Y-m'),
                'occurred_at' => now(),
                'metadata' => json_encode(['run_id' => $index]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedRun(\DateTimeInterface $completedAt): AiVisibilityRun
    {
        $tenantId = app(TenantContext::class)->tenantIdOrDefault();
        $run = AiVisibilityRun::query()->create([
            'tenant_id' => $tenantId,
            'keyword' => 'AI 搜索推荐',
            'keyword_hash' => str_repeat('a', 40),
            'prompt' => 'AI 搜索推荐',
            'provider_type' => AiVisibilityRun::PROVIDER_ENGINE_SAMPLE,
            'provider_key' => AiVisibilityRun::ENGINE_QWEN,
            'engine_key' => AiVisibilityRun::ENGINE_QWEN,
            'engine_variant' => AiVisibilityRun::VARIANT_API,
            'collection_method' => AiVisibilityRun::METHOD_API,
            'search_enabled' => true,
            'is_legacy' => false,
            'status' => AiVisibilityRun::STATUS_COMPLETED,
            'answer_text' => '仍在留存期内',
            'raw_request_json' => ['prompt' => 'x'],
            'raw_response_json' => ['output' => 'y'],
            'completed_at' => $completedAt,
        ]);

        DB::table('ai_visibility_sources')->insert([
            'tenant_id' => $tenantId,
            'ai_visibility_run_id' => (int) $run->id,
            'source_type' => 'search_result',
            'citation_kind' => 'search_result',
            'citation_index' => 1,
            'url' => 'https://example.com/a',
            'domain' => 'example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $run;
    }
}
