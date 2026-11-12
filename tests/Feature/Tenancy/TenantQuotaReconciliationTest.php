<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\ApiException;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantUsageEvent;
use App\Services\Billing\TenantQuotaReconciliationService;
use App\Services\Billing\TenantQuotaService;
use App\Services\Billing\TenantUsageMeter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 月度对账口径：未用额度归零、超用结转次月抵扣、Asia/Shanghai 自然月、
 * 暂停不计量、归档冻结。
 */
class TenantQuotaReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unused_quota_does_not_roll_over_to_the_next_month(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 30]);
        $previous = Carbon::now(config('app.timezone'))->subMonthNoOverflow()->format('Y-m');
        $this->recordPublishedArticles((int) $tenant->id, $previous, 10);

        $reconciliation = app(TenantQuotaReconciliationService::class);
        $report = $reconciliation->ensurePeriod((int) $tenant->id, $this->currentPeriod());

        $this->assertSame(0, $report['article_carryover_in']);
        $this->assertSame(30, $report['article_effective_quota']);
        $this->assertSame(30, $report['article_remaining']);

        $previousReport = $reconciliation->ensurePeriod((int) $tenant->id, $previous);
        $this->assertSame(10, $previousReport['article_used']);
        $this->assertSame(20, $previousReport['article_remaining']);
        $this->assertTrue($previousReport['finalized']);
    }

    public function test_overage_is_carried_into_the_next_month_as_a_credit(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 30]);
        $previous = Carbon::now(config('app.timezone'))->subMonthNoOverflow()->format('Y-m');
        $this->recordPublishedArticles((int) $tenant->id, $previous, 35);

        $report = app(TenantQuotaReconciliationService::class)
            ->ensurePeriod((int) $tenant->id, $this->currentPeriod());

        $this->assertSame(5, $report['article_carryover_in']);
        $this->assertSame(25, $report['article_effective_quota']);

        $this->recordPublishedArticles((int) $tenant->id, $this->currentPeriod(), 24);
        $this->context->useTenant((int) $tenant->id);

        app(TenantQuotaService::class)->assertCanStartGeneration();

        $this->recordPublishedArticles((int) $tenant->id, $this->currentPeriod(), 1);

        try {
            app(TenantQuotaService::class)->assertCanStartGeneration();
            $this->fail('expected quota exception');
        } catch (ApiException $exception) {
            $this->assertSame('tenant_quota_exceeded', $exception->getErrorCode());
            $this->assertStringContainsString('抵扣上月超用结转 5 篇', $exception->getMessage());
        }
    }

    public function test_usage_is_posted_to_the_publish_month_only(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 30]);
        $this->context->useTenant((int) $tenant->id);

        $previousMonth = Carbon::now(config('app.timezone'))->subMonthNoOverflow()->startOfMonth()->addDays(3)->setTime(10, 0);
        $this->publishArticle($previousMonth);
        $this->publishArticle(now());
        $this->publishArticle(now());

        $periods = DB::table('tenant_usage_events')
            ->where('tenant_id', $tenant->id)
            ->selectRaw('period, count(*) as total')
            ->groupBy('period')
            ->pluck('total', 'period')
            ->all();

        $this->assertSame(1, (int) $periods[$previousMonth->format('Y-m')]);
        $this->assertSame(2, (int) $periods[$this->currentPeriod()]);
        $this->assertSame(30, app(TenantQuotaService::class)->summary((int) $tenant->id)['article_quota']);
    }

    public function test_month_boundary_follows_shanghai_natural_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 00:30:00', 'Asia/Shanghai'));

        $tenant = $this->tenantWithQuota(['article_quota' => 30]);
        $this->context->useTenant((int) $tenant->id);

        $this->publishArticle(Carbon::parse('2026-02-28 23:30:00', 'Asia/Shanghai'));
        $this->publishArticle(Carbon::parse('2026-03-01 00:10:00', 'Asia/Shanghai'));

        $this->assertSame(1, app(TenantQuotaReconciliationService::class)
            ->publishedCountForPeriod((int) $tenant->id, '2026-02'));
        $this->assertSame(1, app(TenantQuotaReconciliationService::class)
            ->publishedCountForPeriod((int) $tenant->id, '2026-03'));
    }

    public function test_suspended_tenant_does_not_accrue_new_usage(): void
    {
        $tenant = $this->tenantWithQuota([], 'suspended');
        $this->context->useTenant((int) $tenant->id);

        $recorded = app(TenantUsageMeter::class)->recordPublishedArticle($this->makeArticle());

        $this->assertFalse($recorded);
        $this->assertSame(0, DB::table('tenant_usage_events')->where('tenant_id', $tenant->id)->count());
    }

    public function test_archived_tenant_is_frozen_and_cannot_start_generation(): void
    {
        $tenant = $this->tenantWithQuota([], 'archived');
        $this->context->useTenant((int) $tenant->id);

        try {
            app(TenantQuotaService::class)->assertCanStartGeneration();
            $this->fail('expected archived tenant exception');
        } catch (ApiException $exception) {
            $this->assertSame('tenant_archived', $exception->getErrorCode());
            $this->assertStringContainsString('已归档', $exception->getMessage());
        }

        $report = app(TenantQuotaReconciliationService::class)
            ->ensurePeriod((int) $tenant->id, $this->currentPeriod());

        $this->assertTrue($report['frozen']);
        $this->assertDatabaseMissing('tenant_quota_periods', ['tenant_id' => $tenant->id]);
    }

    public function test_period_snapshot_does_not_change_when_plan_changes_mid_month(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 30]);
        $reconciliation = app(TenantQuotaReconciliationService::class);
        $period = $this->currentPeriod();

        $this->assertSame(30, $reconciliation->ensurePeriod((int) $tenant->id, $period)['article_quota']);

        $tenant->forceFill(['settings' => ['quota' => ['article_quota' => 5]]])->save();

        $this->assertSame(30, $reconciliation->ensurePeriod((int) $tenant->id, $period)['article_quota']);
    }

    public function test_reconcile_command_settles_the_previous_month(): void
    {
        $tenant = $this->tenantWithQuota(['article_quota' => 30]);
        $previous = Carbon::now(config('app.timezone'))->subMonthNoOverflow()->format('Y-m');
        $this->recordPublishedArticles((int) $tenant->id, $previous, 32);

        $this->artisan('geoflow:reconcile-tenant-quota', ['--tenant' => [(string) $tenant->id]])
            ->assertExitCode(0);

        $row = DB::table('tenant_quota_periods')
            ->where('tenant_id', $tenant->id)
            ->where('period', $previous)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(32, (int) $row->article_used);
        $this->assertNotNull($row->finalized_at);
        $this->assertSame(2, (int) DB::table('tenant_quota_periods')
            ->where('tenant_id', $tenant->id)
            ->where('period', $this->currentPeriod())
            ->value('article_carryover_in'));
    }

    /** @param array<string, int> $quota */
    private function tenantWithQuota(array $quota, string $status = 'active'): Tenant
    {
        return Tenant::query()->create([
            'slug' => 'tenant-'.Str::lower(Str::random(8)),
            'name' => 'Reconciliation tenant',
            'status' => $status,
            'plan_code' => 'free',
            'settings' => $quota === [] ? null : ['quota' => $quota],
        ]);
    }

    private function recordPublishedArticles(int $tenantId, string $period, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('tenant_usage_events')->insert([
                'tenant_id' => $tenantId,
                'meter' => TenantUsageEvent::METER_PUBLISHED_ARTICLES,
                'quantity' => 1,
                'source_type' => 'article',
                'source_id' => (string) Str::random(8),
                'idempotency_key' => 'article.published:'.Str::random(12),
                'period' => $period,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function publishArticle(\DateTimeInterface $publishedAt): Article
    {
        $article = $this->makeArticle();
        $article->forceFill(['published_at' => $publishedAt])->save();

        app(TenantUsageMeter::class)->recordPublishedArticle($article->fresh());

        return $article;
    }

    private function makeArticle(): Article
    {
        $task = Task::query()->create([
            'name' => 'Reconciliation task', 'need_review' => 0, 'ai_quality_enabled' => false,
            'status' => 'active', 'schedule_enabled' => 1,
        ]);

        return Article::query()->create([
            'task_id' => $task->id,
            'title' => 'Reconciliation article',
            'slug' => 'reconciliation-'.Str::random(10),
            'content' => 'A factual article.',
            'status' => 'draft',
            'review_status' => 'pending',
            'category_id' => Category::query()->create(['name' => 'Reconciliation', 'slug' => Str::random(10)])->id,
            'author_id' => Author::query()->create(['name' => 'Reconciliation author'])->id,
        ]);
    }

    private function currentPeriod(): string
    {
        return Carbon::now(config('app.timezone'))->format('Y-m');
    }
}
