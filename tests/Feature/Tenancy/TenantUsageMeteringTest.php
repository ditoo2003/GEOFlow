<?php

namespace Tests\Feature\Tenancy;

use App\Exceptions\ArticleAiQualityGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantUsageEvent;
use App\Services\GeoFlow\ArticlePublicationQualityGate;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantUsageMeteringTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->context = app(TenantContext::class);
    }

    public function test_successful_manual_publish_records_one_article(): void
    {
        $article = $this->fixture();

        app(ArticleWorkflowTransitionService::class)->humanAction($article, 'publish');

        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $this->eventCount());
        $event = TenantUsageEvent::query()->firstOrFail();
        $this->assertSame(TenantUsageEvent::METER_PUBLISHED_ARTICLES, $event->meter);
        $this->assertSame(1, (int) $event->quantity);
        $this->assertSame('article.published:'.$article->id, $event->idempotency_key);
        $this->assertSame('article', $event->source_type);
        $this->assertSame((string) $article->id, $event->source_id);
        $this->assertSame(now()->format('Y-m'), $event->period);
    }

    public function test_republishing_the_same_article_still_counts_once(): void
    {
        $article = $this->fixture();
        $service = app(ArticleWorkflowTransitionService::class);

        $service->humanAction($article, 'publish');
        $service->humanAction($article->fresh(), 'publish');
        $service->humanAction($article->fresh(), 'revoke');
        $service->humanAction($article->fresh(), 'publish');

        $this->assertSame(1, $this->eventCount());
    }

    public function test_quality_gate_block_does_not_record_usage(): void
    {
        $article = $this->fixture();
        $gate = \Mockery::mock(ArticlePublicationQualityGate::class);
        $gate->shouldReceive('check')->once()->andThrow(
            new ArticleAiQualityGateException('article_ai_quality_failed', '质检未通过')
        );
        $service = app()->makeWith(ArticleWorkflowTransitionService::class, ['publicationQualityGate' => $gate]);

        try {
            $service->humanAction($article, 'publish');
            $this->fail('expected quality gate exception');
        } catch (ArticleAiQualityGateException) {
            // 质检拦截不计费。
        }

        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame(0, $this->eventCount());
    }

    public function test_workflow_version_conflict_does_not_record_usage(): void
    {
        $article = $this->fixture(['review_status' => 'rejected']);

        try {
            app(ArticleWorkflowTransitionService::class)->humanAction($article, 'publish');
            $this->fail('expected not publishable');
        } catch (\RuntimeException) {
            // 未进入发布态不计费。
        }

        $this->assertSame(0, $this->eventCount());
    }

    public function test_outer_transaction_rollback_discards_the_usage_event(): void
    {
        $article = $this->fixture();

        try {
            DB::transaction(function () use ($article): void {
                app(ArticleWorkflowTransitionService::class)->humanAction($article, 'publish');
                $this->assertSame(1, $this->eventCount());
                throw new \RuntimeException('rollback_probe');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback_probe', $exception->getMessage());
        }

        $this->assertSame(0, $this->eventCount());
    }

    public function test_worker_publish_transition_records_usage(): void
    {
        $task = Task::query()->create([
            'name' => 'Worker publish', 'need_review' => 0, 'ai_quality_enabled' => false,
            'status' => 'active', 'schedule_enabled' => 1,
        ]);
        $article = $this->fixture(['task_id' => $task->id, 'publication_intent' => 'scheduled']);

        app(ArticleWorkflowTransitionService::class)->transition($article, [
            'status' => 'published', 'review_status' => 'auto_approved', 'published_at' => now(),
        ], 'worker_publish');

        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, $this->eventCount());
    }

    public function test_usage_event_is_attributed_to_the_article_tenant(): void
    {
        $tenantB = Tenant::query()->create([
            'slug' => 'tenant-b', 'name' => 'Tenant B', 'status' => 'active',
            'plan_code' => 'free',
        ]);

        $article = $this->context->runAs((int) $tenantB->id, function () use ($tenantB): Article {
            $task = Task::query()->create([
                'name' => 'B task', 'need_review' => 0, 'ai_quality_enabled' => false,
                'status' => 'active', 'schedule_enabled' => 1,
            ]);
            $category = Category::query()->create(['name' => 'B 分类', 'slug' => 'b-cat']);
            $author = Author::query()->create(['name' => 'B 作者']);
            $article = Article::query()->create([
                'task_id' => $task->id, 'title' => 'B 文章', 'slug' => 'b-article',
                'content' => '正文', 'status' => 'draft', 'review_status' => 'pending',
                'category_id' => $category->id, 'author_id' => $author->id,
            ]);

            return app(ArticleWorkflowTransitionService::class)
                ->humanAction($article, 'publish', $this->admin($tenantB)->id);
        });

        $this->assertSame((int) $tenantB->id, (int) DB::table('tenant_usage_events')
            ->where('idempotency_key', 'article.published:'.$article->id)->value('tenant_id'));

        $tenantC = Tenant::query()->create([
            'slug' => 'tenant-c', 'name' => 'Tenant C', 'status' => 'active',
            'plan_code' => 'free',
        ]);
        $this->context->useTenant((int) $tenantC->id);
        $this->assertSame(0, $this->eventCount());
    }

    private function eventCount(): int
    {
        return TenantUsageEvent::query()->count();
    }

    /** @param array<string, mixed> $attributes */
    private function fixture(array $attributes = []): Article
    {
        $task = Task::query()->create([
            'name' => 'Metering task', 'need_review' => 0, 'ai_quality_enabled' => false,
            'status' => 'active', 'schedule_enabled' => 1,
        ]);

        return Article::query()->create(array_merge([
            'task_id' => $task->id,
            'title' => 'Metering article',
            'slug' => 'metering-'.uniqid(),
            'content' => 'A factual article.',
            'status' => 'draft',
            'review_status' => 'pending',
            'category_id' => Category::query()->create(['name' => 'Metering', 'slug' => uniqid('metering-')])->id,
            'author_id' => Author::query()->create(['name' => 'Metering author'])->id,
        ], $attributes));
    }

    private function admin(Tenant $tenant): Admin
    {
        return Admin::query()->create([
            'username' => 'metering-admin-'.uniqid(), 'password' => 'secret', 'role' => 'admin', 'status' => 'active',
        ]);
    }
}
