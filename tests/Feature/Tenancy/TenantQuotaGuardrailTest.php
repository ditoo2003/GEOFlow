<?php

namespace Tests\Feature\Tenancy;

use App\Data\Ai\AiExecutionContext;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Tenant;
use App\Models\TenantQuotaAlert;
use App\Services\Billing\TenantQuotaAlertService;
use App\Services\Billing\TenantQuotaService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class TenantQuotaGuardrailTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
    }

    public function test_token_pressure_moves_from_normal_to_degrade_to_block(): void
    {
        $tenant = $this->tenantWithQuota(['token_budget' => 1000]);
        $quota = app(TenantQuotaService::class);

        $this->recordTokenUsage((int) $tenant->id, 799);
        $this->assertSame('normal', $quota->tokenPressure((int) $tenant->id));

        $this->recordTokenUsage((int) $tenant->id, 1);
        $this->assertSame('degrade', $quota->tokenPressure((int) $tenant->id));

        $this->recordTokenUsage((int) $tenant->id, 200);
        $this->assertSame('block', $quota->tokenPressure((int) $tenant->id));
    }

    public function test_article_quota_counts_as_blocking_pressure(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 1]);
        $this->recordPublishedArticle((int) $tenant->id);

        $this->assertSame('block', app(TenantQuotaService::class)->pressure((int) $tenant->id));
    }

    public function test_threshold_alerts_are_deduplicated_per_period_and_level(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 1, 'token_budget' => 1000]);
        $this->recordPublishedArticle((int) $tenant->id);
        $this->recordTokenUsage((int) $tenant->id, 800);

        $alerts = app(TenantQuotaAlertService::class);
        $alerts->evaluate((int) $tenant->id);
        $alerts->evaluate((int) $tenant->id);

        $this->assertSame(1, DB::table('tenant_quota_alerts')
            ->where('tenant_id', $tenant->id)->where('meter', TenantQuotaAlert::METER_PUBLISHED_ARTICLES)->count());
        $this->assertSame(1, DB::table('tenant_quota_alerts')
            ->where('tenant_id', $tenant->id)->where('meter', TenantQuotaAlert::METER_TOKENS)->count());
        $this->assertStringContainsString('本月已发布文章数已达套餐上限', (string) DB::table('tenant_quota_alerts')
            ->where('meter', TenantQuotaAlert::METER_PUBLISHED_ARTICLES)->value('message'));
        $this->assertStringContainsString('已自动降级', (string) DB::table('tenant_quota_alerts')
            ->where('meter', TenantQuotaAlert::METER_TOKENS)->value('message'));
    }

    public function test_degrade_model_becomes_the_first_candidate_and_original_stays_as_failover(): void
    {
        [$task, $primary, $fallback, $downgrade, $context] = $this->downgradeFixture();

        $this->assertSame('degrade', app(TenantQuotaService::class)->tokenPressure((int) $task->tenant_id));
        $this->assertSame((int) $downgrade->id, app(\App\Services\Billing\TenantPlanCatalog::class)->downgradeModelId((int) $task->tenant_id));

        $candidates = $this->applyQuotaDowngrade($task, $context, [$primary, $fallback]);

        $this->assertCount(3, $candidates);
        $this->assertSame((int) $downgrade->id, (int) $candidates[0]->id);
        $this->assertSame((int) $primary->id, (int) $candidates[1]->id);
        $this->assertSame((int) $fallback->id, (int) $candidates[2]->id);
    }

    public function test_unavailable_degrade_model_keeps_original_candidates_and_raises_alert(): void
    {
        [$task, $primary, $fallback, $downgrade, $context] = $this->downgradeFixture();

        $downgrade->forceFill(['status' => 'inactive'])->save();

        $candidates = $this->applyQuotaDowngrade($task, $context, [$primary, $fallback]);

        $this->assertSame([(int) $primary->id, (int) $fallback->id], array_map(
            static fn (AiModel $model): int => (int) $model->id,
            $candidates,
        ));
        $this->assertDatabaseHas('tenant_quota_alerts', [
            'tenant_id' => $task->tenant_id,
            'level' => TenantQuotaAlert::LEVEL_DEGRADE_UNAVAILABLE,
        ]);
    }

    public function test_no_downgrade_below_the_threshold(): void
    {
        [$task, $primary, $fallback, , $context] = $this->downgradeFixture(usage: 100);

        $candidates = $this->applyQuotaDowngrade($task, $context, [$primary, $fallback]);

        $this->assertSame([(int) $primary->id, (int) $fallback->id], array_map(
            static fn (AiModel $model): int => (int) $model->id,
            $candidates,
        ));
    }

    /**
     * @return array{0: Task, 1: AiModel, 2: AiModel, 3: AiModel, 4: AiExecutionContext}
     */
    private function downgradeFixture(int $usage = 800): array
    {
        $tenant = $this->tenantWithQuota([
            'token_budget' => 1000,
        ]);
        $this->recordTokenUsage((int) $tenant->id, $usage);
        $this->context->useTenant((int) $tenant->id);

        $admin = Admin::query()->create([
            'username' => 'quota-admin-'.Str::lower(Str::random(8)),
            'password' => 'secret',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $admin->forceFill(['ai_config_access_version' => 1])->save();

        $primary = $this->model($admin, 'primary-'.Str::lower(Str::random(6)));
        $fallback = $this->model($admin, 'fallback-'.Str::lower(Str::random(6)));
        $downgrade = $this->model($admin, 'downgrade-'.Str::lower(Str::random(6)));

        $tenant->forceFill([
            'settings' => ['quota' => [
                'token_budget' => 1000,
                'downgrade_model_id' => (int) $downgrade->id,
            ]],
        ])->save();

        $task = new Task(['name' => 'Quota downgrade task', 'status' => 'active', 'schedule_enabled' => 1]);
        $task->forceFill([
            'tenant_id' => (int) $tenant->id,
            'model_access_admin_id' => (int) $admin->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ])->save();

        $run = TaskRun::query()->create(['task_id' => $task->id, 'status' => 'running']);
        $run->forceFill([
            'model_access_admin_id' => (int) $admin->id,
            'model_access_admin_role' => 'super_admin',
            'ai_config_access_version' => 1,
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            'execution_lease_token' => 'lease-'.Str::lower(Str::random(20)),
        ])->save();

        return [$task->fresh(), $primary, $fallback, $downgrade, AiExecutionContext::fromPersistedTaskRun($run->fresh())];
    }

    /** @param list<AiModel> $candidates
     *  @return list<AiModel>
     */
    private function applyQuotaDowngrade(Task $task, AiExecutionContext $context, array $candidates): array
    {
        $method = new ReflectionMethod(WorkerExecutionService::class, 'applyQuotaDowngrade');
        $method->setAccessible(true);

        return $method->invoke(app(WorkerExecutionService::class), $task, $context, $candidates);
    }

    private function model(Admin $owner, string $modelId): AiModel
    {
        $model = new AiModel([
            'name' => $modelId,
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quota-test-key'),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    /** @param array<string, int> $quota */
    private function tenantWithQuota(array $quota): Tenant
    {
        return Tenant::query()->create([
            'slug' => 'tenant-'.Str::lower(Str::random(8)),
            'name' => 'Guardrail tenant',
            'status' => 'active',
            'plan_code' => 'free',
            'settings' => ['quota' => $quota],
        ]);
    }

    private function recordPublishedArticle(int $tenantId): void
    {
        DB::table('tenant_usage_events')->insert([
            'tenant_id' => $tenantId,
            'meter' => 'articles_published',
            'quantity' => 1,
            'source_type' => 'article',
            'source_id' => '1',
            'idempotency_key' => 'article.published:'.Str::random(8),
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
            'call_key' => 'quota-guardrail',
            'payload_fingerprint' => str_repeat('b', 64),
            'operation' => 'generate',
            'ai_model_id' => 1,
            'config_owner_admin_id' => 1,
            'ai_config_access_version' => 0,
            'execution_scope' => 'system',
            'model_source' => 'system',
            'business_source' => 'quota_guardrail',
            'status' => 'succeeded',
            'total_tokens' => $tokens,
            'tenant_id' => $tenantId,
            'created_at' => now(),
        ]);
    }
}
