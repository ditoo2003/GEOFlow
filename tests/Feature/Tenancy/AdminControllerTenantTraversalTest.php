<?php

namespace Tests\Feature\Tenancy;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\LeadForm;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TitleLibrary;
use App\Models\Topic;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 后台控制器租户遍历。
 *
 * 目的是把"每个后台页面都在当前租户上下文内运行"变成可回归的断言：
 * - 遍历所有无参数的后台 GET 页面，以及带参数的 GET 端点（用不存在的 id 探测）；
 * - 每次请求后都必须仍是同一租户上下文，不允许页面把全局上下文切到系统态后不恢复；
 * - 任何页面返回 200 时都不能出现另一个租户的探针数据；
 * - 除 4 个只有写接口的控制器外，69 个后台控制器都必须被覆盖到。
 */
class AdminControllerTenantTraversalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 跨租户探针：只写入租户 C，任何租户 B 页面出现该字符串即为串数据。
     */
    private const FOREIGN_MARKER = 'TENANTCLEAKPROBE';

    /**
     * 登录入口、语言切换与退出不属于租户隔离面，单独由认证测试覆盖：
     * 它们不在 `auth:admin` 分组内，不读取任何租户业务数据。
     */
    private const SKIPPED_ROUTE_NAMES = [
        'admin.entry',
        'admin.login',
        'admin.logout',
        'admin.locale.switch',
    ];

    /**
     * 这些控制器无法通过"带租户管理员会话的后台 GET 页面"触达：
     * - 前 4 个只有写接口（上传/保存/问候），由写路径测试与模型作用域测试覆盖；
     * - AdminAuthController 只有登录/退出/语言切换，属于认证面，不读取租户业务数据。
     */
    private const EXEMPT_CONTROLLERS = [
        'App\Http\Controllers\Admin\AdminWelcomeController',
        'App\Http\Controllers\Admin\AiWorkspaceRuntimeSettingsController',
        'App\Http\Controllers\Admin\ArticleEditorAssetController',
        'App\Http\Controllers\Admin\KnowledgeBaseMediaController',
        'App\Http\Controllers\Admin\AdminAuthController',
    ];

    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = app(TenantContext::class);
    }

    public function test_every_admin_get_page_runs_inside_the_resolved_tenant(): void
    {
        [$tenantB, $tenantC] = $this->makeTenants();
        $this->seedForeignTenantData($tenantC);

        $admin = $this->makeAdmin('tenant-b-traversal');
        TenantMembership::query()->create([
            'tenant_id' => $tenantB->id,
            'admin_id' => $admin->id,
            'role' => TenantMembership::ROLE_ADMIN,
        ]);

        $this->actingAs($admin, 'admin');

        $failures = [];
        $coveredControllers = [];

        foreach ($this->adminGetRoutes() as $route) {
            $controller = $this->controllerClass($route);
            if ($controller !== null) {
                $coveredControllers[$controller] = true;
            }

            $uri = $this->requestUri($route);
            $response = $this->get($uri);
            $status = $response->getStatusCode();

            if (! in_array($status, [200, 302, 400, 403, 404], true)) {
                $failures[] = sprintf('%s [%s] 返回 %d', $route->uri(), (string) $route->getName(), $status);

                continue;
            }

            if ($status === 200 && str_contains($response->getContent(), self::FOREIGN_MARKER)) {
                $failures[] = sprintf('%s [%s] 泄漏了其它租户数据', $route->uri(), (string) $route->getName());
            }

            $currentTenant = $this->context->tenantId();
            if ($currentTenant !== (int) $tenantB->id) {
                $failures[] = sprintf(
                    '%s [%s] 结束后租户上下文变为 %s',
                    $route->uri(),
                    (string) $route->getName(),
                    $currentTenant === null ? 'system/未解析' : (string) $currentTenant,
                );
            }
        }

        $this->assertSame([], $failures, "后台页面租户遍历失败：\n".implode("\n", $failures));

        $uncovered = array_values(array_diff($this->adminControllerClasses(), array_keys($coveredControllers), self::EXEMPT_CONTROLLERS));
        $this->assertSame([], $uncovered, '以下后台控制器没有被租户遍历覆盖：'.implode(', ', $uncovered));
    }

    /**
     * @return array{0: Tenant, 1: Tenant}
     */
    private function makeTenants(): array
    {
        return [
            Tenant::query()->create(['name' => 'tenant-b', 'slug' => 'tenant-b', 'status' => 'active']),
            Tenant::query()->create(['name' => 'tenant-c', 'slug' => 'tenant-c', 'status' => 'active']),
        ];
    }

    private function makeAdmin(string $username, string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /**
     * 在租户 C 写入可识别的探针数据，覆盖后台列表页最容易串数据的几类实体。
     */
    private function seedForeignTenantData(Tenant $tenant): void
    {
        $this->context->runAs((int) $tenant->id, function (): void {
            $category = Category::query()->create([
                'name' => self::FOREIGN_MARKER.'-分类',
                'slug' => 'tenent-c-category',
            ]);
            $author = Author::query()->create(['name' => self::FOREIGN_MARKER.'-作者']);

            Article::query()->create([
                'title' => self::FOREIGN_MARKER.'-文章',
                'slug' => 'tenant-c-article',
                'content' => self::FOREIGN_MARKER.'-正文',
                'category_id' => $category->id,
                'author_id' => $author->id,
            ]);

            Topic::query()->create([
                'site_key' => 'default',
                'slug' => 'tenant-c-topic',
                'title' => self::FOREIGN_MARKER.'-专题',
                'normalized_title_key' => hash('sha256', self::FOREIGN_MARKER),
                'draft_payload' => [],
            ]);

            KnowledgeBase::query()->create([
                'name' => self::FOREIGN_MARKER.'-知识库',
                'content' => self::FOREIGN_MARKER.'-知识库正文',
            ]);
            TitleLibrary::query()->create(['name' => self::FOREIGN_MARKER.'-标题库']);
            ImageLibrary::query()->create(['name' => self::FOREIGN_MARKER.'-图片库']);
            LeadForm::query()->create([
                'name' => self::FOREIGN_MARKER.'-表单',
                'slug' => 'tenant-c-lead-form',
                'status' => LeadForm::STATUS_ACTIVE,
                'fields' => [],
            ]);

            AiModel::query()->create([
                'name' => self::FOREIGN_MARKER.'-模型',
                'api_key' => 'tenant-c-key',
                'model_id' => 'tenant-c-model',
                'access_scope' => 'user_content',
            ]);
        });
    }

    /**
     * @return array<int, RoutingRoute>
     */
    private function adminGetRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static function (RoutingRoute $route): bool {
                $name = (string) $route->getName();

                return in_array('GET', $route->methods(), true)
                    && str_starts_with($name, 'admin.')
                    && ! in_array($name, self::SKIPPED_ROUTE_NAMES, true);
            },
        ));
    }

    private function requestUri(RoutingRoute $route): string
    {
        $uri = '/'.preg_replace('/\/\{[^}]*\?\}/', '', $route->uri());

        $uri = preg_replace_callback('/\{(\w+)\}/', static function (array $matches): string {
            return str_contains(strtolower($matches[1]), 'uuid')
                ? '00000000-0000-0000-0000-000000000000'
                : '987654321';
        }, $uri);

        return rtrim($uri, '/');
    }

    private function controllerClass(RoutingRoute $route): ?string
    {
        $action = $route->getActionName();
        if ($action === 'Closure') {
            return null;
        }

        $class = str_contains($action, '@') ? strstr($action, '@', true) : $action;

        return str_starts_with((string) $class, 'App\Http\Controllers\Admin\\') ? (string) $class : null;
    }

    /**
     * 路由注册到的后台控制器全集（与 app/Http/Controllers/Admin 下的 69 个文件一一对应）。
     *
     * @return array<int, string>
     */
    private function adminControllerClasses(): array
    {
        $classes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $class = $this->controllerClass($route);
            if ($class !== null) {
                $classes[$class] = true;
            }
        }

        return array_keys($classes);
    }
}
