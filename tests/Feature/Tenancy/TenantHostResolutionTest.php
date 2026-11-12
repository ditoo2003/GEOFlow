<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveTenantFromHost;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantHostResolutionTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
        // 模拟真实请求进入时的初始状态：请求开始前没有租户上下文。
        $this->context->clear();
        config()->set('geoflow.hosted_sites.primary_hosts', ['localhost', 'primary.test']);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
    }

    public function test_primary_host_maps_to_the_configured_default_tenant(): void
    {
        $this->dispatchHost('primary.test');

        $this->assertSame((int) config('tenancy.default_tenant_id', 1), $this->context->tenantId());
    }

    public function test_unbound_hostname_stays_unresolved_instead_of_falling_back_to_default_tenant(): void
    {
        config()->set('geoflow.hosted_sites.enabled', true);

        $this->dispatchHost('unknown.sites.test');

        $this->assertFalse($this->context->isResolved());
        $this->assertNull($this->context->tenantId());
    }

    public function test_hosted_site_hostname_maps_to_its_owning_tenant(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'tenant-b',
            'slug' => 'tenant-b',
            'status' => 'active',
        ]);

        $this->context->runAs((int) $tenant->id, function (): void {
            $channel = DistributionChannel::query()->create([
                'name' => 'Alpha',
                'domain' => 'alpha.sites.test',
                'endpoint_url' => 'https://alpha.sites.test',
                'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
                'status' => DistributionChannel::STATUS_ACTIVE,
                'template_key' => 'default',
                'site_settings' => ['site_name' => 'Alpha Site'],
            ]);

            HostedSiteProfile::query()->create([
                'distribution_channel_id' => $channel->id,
                'hostname' => 'alpha.sites.test',
                'root_domain' => 'sites.test',
                'topic' => 'AI',
                'serving_status' => HostedSiteProfile::SERVING_ONLINE,
            ]);
        });

        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);

        $this->dispatchHost('alpha.sites.test');

        $this->assertSame((int) $tenant->id, $this->context->tenantId());
    }

    public function test_unbound_host_returns_not_found_over_http_and_leaves_context_unresolved(): void
    {
        config()->set('geoflow.hosted_sites.enabled', true);

        $this->get('http://unknown.sites.test/')->assertNotFound();

        $this->assertFalse($this->context->isResolved());
    }

    public function test_primary_host_keeps_serving_the_public_site(): void
    {
        $this->get('http://primary.test/')->assertOk();
    }

    private function dispatchHost(string $hostname): void
    {
        $request = Request::create('http://'.$hostname.'/');
        $request->attributes->set('normalized_host', $hostname);

        app(ResolveTenantFromHost::class)->handle($request, static fn () => response('ok'));
    }
}
