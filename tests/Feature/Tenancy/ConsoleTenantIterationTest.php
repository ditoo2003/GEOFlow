<?php

namespace Tests\Feature\Tenancy;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\UrlImportJob;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ConsoleTenantIterationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_scheduler_runs_per_tenant_and_creates_runs_in_the_owning_tenant(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());

        $second = Tenant::query()->create(['name' => '租户二', 'slug' => 'tenant-two', 'status' => 'active']);
        $context = app(TenantContext::class);

        $firstTask = $this->dueTask('租户一任务');
        $secondTask = $context->runAs((int) $second->id, fn (): Task => $this->dueTask('租户二任务'));

        $exit = Artisan::call('geoflow:schedule-tasks');

        $this->assertSame(0, $exit);
        $runs = DB::table('task_runs')
            ->whereIn('task_id', [(int) $firstTask->id, (int) $secondTask->id])
            ->select(['task_id', 'tenant_id'])
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $runs);
        $tenantByTask = $runs->mapWithKeys(
            static fn (object $run): array => [(int) $run->task_id => (int) $run->tenant_id]
        )->all();
        $this->assertSame((int) config('tenancy.default_tenant_id', 1), $tenantByTask[(int) $firstTask->id]);
        $this->assertSame((int) $second->id, $tenantByTask[(int) $secondTask->id]);
    }

    public function test_scheduler_tenant_option_limits_execution_to_the_requested_tenant(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());

        $second = Tenant::query()->create(['name' => '租户三', 'slug' => 'tenant-three', 'status' => 'active']);
        $context = app(TenantContext::class);

        $firstTask = $this->dueTask('租户一任务');
        $secondTask = $context->runAs((int) $second->id, fn (): Task => $this->dueTask('租户三任务'));

        $exit = Artisan::call('geoflow:schedule-tasks', ['--tenant' => [(string) $second->id]]);

        $this->assertSame(0, $exit);
        $taskIds = DB::table('task_runs')->pluck('task_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $this->assertContains((int) $secondTask->id, $taskIds);
        $this->assertNotContains((int) $firstTask->id, $taskIds);
    }

    public function test_unknown_tenant_option_fails_with_a_readable_chinese_error(): void
    {
        $exit = Artisan::call('geoflow:prune-link-pool', ['--tenant' => ['999']]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('租户 999 不存在', Artisan::output());
    }

    public function test_malformed_tenant_option_is_rejected(): void
    {
        $exit = Artisan::call('geoflow:prune-link-pool', ['--tenant' => ['abc']]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('租户参数无效：abc', Artisan::output());
    }

    public function test_manifest_based_command_requires_an_explicit_tenant_in_multi_tenant_deployments(): void
    {
        Tenant::query()->create(['name' => '租户四', 'slug' => 'tenant-four', 'status' => 'active']);

        $exit = Artisan::call('geoflow:reconcile-article-workflow');

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('该命令一次只能处理一个租户', Artisan::output());
    }

    public function test_url_import_worker_rejects_an_unknown_explicit_tenant(): void
    {
        $job = UrlImportJob::query()->create([
            'url' => 'https://example.test/article',
            'normalized_url' => 'https://example.test/article',
            'status' => 'queued',
        ]);

        $exit = Artisan::call('geoflow:process-url-import', ['jobId' => (int) $job->id, '--tenant' => '999']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('租户参数无效：999', Artisan::output());
    }

    private function dueTask(string $name): Task
    {
        $task = Task::query()->create([
            'name' => $name,
            'status' => 'active',
            'schedule_enabled' => 1,
            'next_run_at' => now()->subMinute(),
        ]);

        $category = Category::query()->firstOrCreate(
            ['slug' => 'tenant-iteration-category'],
            ['name' => 'Tenant iteration category'],
        );
        $author = Author::query()->firstOrCreate(['name' => 'Tenant iteration author']);
        Article::query()->create([
            'title' => 'Due draft for '.$name,
            'slug' => 'due-draft-'.$task->id,
            'content' => 'Existing approved draft.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'approved',
        ]);

        return $task;
    }
}
