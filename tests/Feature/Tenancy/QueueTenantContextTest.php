<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\TenantProbeJob;
use Tests\TestCase;

class QueueTenantContextTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
        TenantProbeJob::$observed = [];
    }

    public function test_job_runs_in_dispatch_tenant_and_restores_caller_context(): void
    {
        $tenant = $this->makeTenant('tenant-b');
        $this->context->useTenant((int) $tenant->id);

        dispatch_sync(new TenantProbeJob('tenant-job'));

        $this->assertSame((int) $tenant->id, TenantProbeJob::$observed['tenant-job']);
        $this->assertSame((int) $tenant->id, $this->context->tenantId());
    }

    public function test_nested_dispatch_inherits_tenant_and_restores_outer_context(): void
    {
        $tenant = $this->makeTenant('tenant-b');
        $this->context->useTenant((int) $tenant->id);

        dispatch_sync(new TenantProbeJob('outer', true));

        $this->assertSame((int) $tenant->id, TenantProbeJob::$observed['outer']);
        $this->assertSame((int) $tenant->id, TenantProbeJob::$observed['outer-nested']);
        $this->assertSame((int) $tenant->id, $this->context->tenantId());
    }

    public function test_system_context_jobs_run_as_system(): void
    {
        $this->context->useSystem();

        dispatch_sync(new TenantProbeJob('system-job'));

        $this->assertSame('system', TenantProbeJob::$observed['system-job']);
        $this->assertTrue($this->context->isSystem());
    }

    public function test_single_tenant_deployment_falls_back_when_payload_is_missing(): void
    {
        $this->context->clear();
        $this->assertSame(1, Tenant::query()->count());

        dispatch_sync(new TenantProbeJob('legacy-job'));

        $this->assertSame(
            (int) config('tenancy.default_tenant_id', 1),
            TenantProbeJob::$observed['legacy-job']
        );
        $this->assertFalse($this->context->isResolved());
    }

    public function test_multi_tenant_missing_payload_fails_closed_with_readable_error(): void
    {
        $this->makeTenant('tenant-b');
        $this->context->clear();
        Log::spy();

        try {
            dispatch_sync(new TenantProbeJob('orphan-job'));
            $this->fail('缺少租户快照的队列任务应当被拒绝执行');
        } catch (TenantContextMissingException $exception) {
            $this->assertStringContainsString('租户', $exception->getMessage());
            $this->assertStringContainsString(TenantProbeJob::class, $exception->getMessage());
        }

        $this->assertSame([], TenantProbeJob::$observed);
        $this->assertFalse($this->context->isResolved());

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'queue.tenant_context_missing'
                && ($context['job'] ?? '') === TenantProbeJob::class)
            ->atLeast()
            ->once();
    }

    public function test_payload_keeps_tenant_snapshot_for_delayed_jobs_and_retries(): void
    {
        $tenant = $this->makeTenant('tenant-b');
        $this->context->useTenant((int) $tenant->id);

        config(['queue.default' => 'database']);
        dispatch((new TenantProbeJob('delayed-job'))->delay(60));

        $row = DB::table('jobs')->first(['payload', 'available_at']);
        $this->assertNotNull($row);

        $payload = json_decode((string) $row->payload, true);
        $this->assertSame((int) $tenant->id, (int) ($payload['tenant_id'] ?? 0));
        // 延迟与重试复用的都是这条 payload，因此快照不会丢。
        $this->assertGreaterThan(time(), (int) $row->available_at);
        $this->assertSame([], TenantProbeJob::$observed);
    }

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'status' => 'active',
        ]);
    }
}
