<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\ApiException;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantUsageEvent;
use App\Services\Billing\TenantQuotaService;
use App\Services\HostedSites\HostedSiteLifecycleService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Tenancy\TenantContext;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantQuotaEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
        $this->context = app(TenantContext::class);
        $this->context->useTenant((int) config('tenancy.default_tenant_id', 1));
    }

    public function test_site_limit_blocks_new_sites_but_ignores_archived_ones(): void
    {
        $payload = [
            'name' => '站点 A',
            'hostname' => 'alpha.sites.test',
            'topic' => 'AI',
            'locale' => 'zh_CN',
            'timezone' => 'Asia/Shanghai',
            'daily_publish_limit' => 3,
            'min_publish_interval_minutes' => 0,
            'min_articles_before_index' => 1,
        ];

        $service = app(HostedSiteLifecycleService::class);
        $channel = $service->create($payload, null);
        $this->assertSame(1, app(TenantQuotaService::class)->siteCount((int) config('tenancy.default_tenant_id', 1)));

        try {
            $service->create(['hostname' => 'beta.sites.test'] + $payload, null);
            $this->fail('expected site quota block');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('站点数已达上限（1 个）', $exception->getMessage());
        }

        DB::table('hosted_site_profiles')
            ->where('distribution_channel_id', (int) $channel->id)
            ->update(['archived_at' => now()]);

        $this->assertSame(0, app(TenantQuotaService::class)->siteCount((int) config('tenancy.default_tenant_id', 1)));
        $service->create(['hostname' => 'beta.sites.test'] + $payload, null);
        $this->assertDatabaseHas('hosted_site_profiles', ['hostname' => 'beta.sites.test']);
    }

    public function test_article_quota_blocks_new_generation_with_article_message(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 1]);
        $this->context->useTenant((int) $tenant->id);
        $this->recordPublishedArticle($tenant->id);

        $task = Task::query()->create(['name' => 'Quota task', 'status' => 'active', 'schedule_enabled' => 1]);

        try {
            app(TaskLifecycleService::class)->startTask((int) $task->id);
            $this->fail('expected article quota block');
        } catch (ApiException $exception) {
            $this->assertSame('tenant_quota_exceeded', $exception->getErrorCode());
            $this->assertStringContainsString('本月已发布文章数已达套餐上限（1 篇）', $exception->getMessage());
        }
    }

    public function test_token_budget_blocks_new_generation_with_token_message(): void
    {
        $tenant = $this->tenantWithQuota(['token_budget' => 1000]);
        $this->context->useTenant((int) $tenant->id);
        $this->recordTokenUsage($tenant->id, 1000);

        try {
            app(TenantQuotaService::class)->assertCanStartGeneration();
            $this->fail('expected token budget block');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('本月 AI 成本额度已用完', $exception->getMessage());
            $this->assertStringNotContainsString('本月已发布文章数', $exception->getMessage());
        }
    }

    public function test_article_quota_takes_precedence_when_both_limits_are_hit(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 1, 'token_budget' => 1000]);
        $this->context->useTenant((int) $tenant->id);
        $this->recordPublishedArticle($tenant->id);
        $this->recordTokenUsage($tenant->id, 1000);

        try {
            app(TenantQuotaService::class)->assertCanStartGeneration();
            $this->fail('expected quota block');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('本月已发布文章数已达套餐上限', $exception->getMessage());
        }
    }

    public function test_other_tenant_is_not_affected_by_exhausted_quota(): void
    {
        $exhausted = $this->tenantWithQuota(['article_quota' => 1]);
        $other = Tenant::query()->create([
            'slug' => 'tenant-other', 'name' => 'Other', 'status' => 'active', 'plan_code' => 'enterprise',
        ]);

        $this->context->useTenant((int) $exhausted->id);
        $this->recordPublishedArticle($exhausted->id);

        $this->context->useTenant((int) $other->id);
        app(TenantQuotaService::class)->assertCanStartGeneration();
        $this->assertSame(0, app(TenantQuotaService::class)->monthlyPublishedCount((int) $other->id));
    }

    public function test_system_context_without_selected_tenant_requires_explicit_tenant(): void
    {
        $this->context->useSystem();

        try {
            app(TenantQuotaService::class)->assertCanStartGeneration();
            $this->fail('expected unresolved tenant error');
        } catch (ApiException $exception) {
            $this->assertStringContainsString('请先在后台选择租户', $exception->getMessage());
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('请先在后台选择租户后再创建站点。');
        app(TenantQuotaService::class)->assertCanCreateSite();
    }

    /** @param array<string, int> $quota */
    private function tenantWithQuota(array $quota): Tenant
    {
        return Tenant::query()->create([
            'slug' => 'tenant-'.Str::lower(Str::random(8)),
            'name' => 'Quota tenant',
            'status' => 'active',
            'plan_code' => 'free',
            'settings' => ['quota' => $quota],
        ]);
    }

    private function recordPublishedArticle(int $tenantId): void
    {
        DB::table('tenant_usage_events')->insert([
            'tenant_id' => $tenantId,
            'meter' => TenantUsageEvent::METER_PUBLISHED_ARTICLES,
            'quantity' => 1,
            'source_type' => 'article',
            'source_id' => '1',
            'idempotency_key' => 'article.published:1',
            'period' => now()->format('Y-m'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recordTokenUsage(int $tenantId, int $tokens): void
    {
        DB::table('ai_model_usage_events')->insert([
            'event_uuid' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'request_payload_digest' => str_repeat('a', 64),
            'call_key' => 'quota-test',
            'payload_fingerprint' => str_repeat('b', 64),
            'operation' => 'generate',
            'ai_model_id' => 1,
            'config_owner_admin_id' => 1,
            'ai_config_access_version' => 0,
            'execution_scope' => 'system',
            'model_source' => 'system',
            'business_source' => 'quota_test',
            'status' => 'succeeded',
            'total_tokens' => $tokens,
            'tenant_id' => $tenantId,
            'created_at' => now(),
        ]);
    }
}
