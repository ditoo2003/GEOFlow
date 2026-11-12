<?php

namespace Tests;

use App\Contracts\Outbound\HostResolver;
use App\Models\Admin;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeHostResolver;

/**
 * 测试基类：Feature 测试如需数据库可在用例中 use {@see RefreshDatabase}。
 */
abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        if ($guard === 'admin' && $user instanceof Admin) {
            $this->app['session']->put(
                Admin::AUTH_VERSION_SESSION_KEY,
                (int) $user->auth_version
            );
        }

        return $this;
    }

    public function createApplication()
    {
        $this->forceTestingEnvironment();

        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.pgsql.url', null);
        $app->singleton(HostResolver::class, FakeHostResolver::class);

        // 存量测试全部在默认租户下运行；多租户用例自行覆盖上下文。
        $app->make(TenantContext::class)->useTenant((int) config('tenancy.default_tenant_id', 1));

        return $app;
    }

    private function forceTestingEnvironment(): void
    {
        $variables = [
            'ADMIN_BASE_PATH' => 'geo_admin',
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'SITE_NAME' => 'GEOFlow',
        ];

        foreach ($variables as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }
}
