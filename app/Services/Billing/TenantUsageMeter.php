<?php

namespace App\Services\Billing;

use App\Models\Article;
use App\Models\TenantUsageEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 租户用量计量（对外计费事实）。
 *
 * 口径：只有"通过质检并发布"的文章才计 1 篇；同一篇文章撤下后再发布仍只计 1 篇，
 * 幂等键固定为 `article.published:{article_id}`（不含 workflow_version）。
 * 多站点分发不重复计数，因为计量发生在文章进入 `status=published` 的发布事务内。
 *
 * 写入使用 `insertOrIgnore` 并显式带 `tenant_id`：RLS 的 WITH CHECK 需要正确的租户归属，
 * 不能依赖 Eloquent creating 钩子（它不覆盖系统上下文）。
 *
 * 暂停/归档租户不计新账（历史账保留）；跨月按 Asia/Shanghai 自然月归档入账月份。
 */
final class TenantUsageMeter
{
    public function __construct(
        private readonly TenantQuotaReconciliationService $reconciliation,
    ) {}

    public function recordPublishedArticle(Article $article): bool
    {
        $articleId = (int) $article->getKey();
        if ($articleId <= 0) {
            return false;
        }

        $occurredAt = $article->published_at instanceof \DateTimeInterface
            ? Carbon::instance($article->published_at)->setTimezone(config('app.timezone'))
            : now(config('app.timezone'));
        $tenantId = $this->tenantIdFor($article);

        if (! $this->reconciliation->tenantAllowsAccrual($tenantId)) {
            Log::info('tenant_usage.metering_skipped', [
                'tenant_id' => $tenantId,
                'article_id' => $articleId,
                'reason' => 'tenant_not_active',
            ]);

            return false;
        }

        $inserted = DB::table('tenant_usage_events')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'meter' => TenantUsageEvent::METER_PUBLISHED_ARTICLES,
            'quantity' => 1,
            'source_type' => 'article',
            'source_id' => (string) $articleId,
            'idempotency_key' => 'article.published:'.$articleId,
            'period' => $occurredAt->format('Y-m'),
            'occurred_at' => $occurredAt,
            'metadata' => json_encode([
                'article_id' => $articleId,
                'task_id' => $article->task_id !== null ? (int) $article->task_id : null,
                'site_key' => $article->getAttribute('site_key'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]) > 0;

        if ($inserted) {
            app(TenantQuotaAlertService::class)->evaluate($tenantId);
        }

        return $inserted;
    }

    /**
     * 计量归属租户：优先当前上下文；系统上下文（超管/调度）按文章自身归属，最终回落默认租户。
     */
    public function tenantIdFor(Article $article): int
    {
        $contextTenantId = app(TenantContext::class)->tenantId();
        if ($contextTenantId !== null) {
            return $contextTenantId;
        }

        $articleTenantId = $article->getAttribute('tenant_id');
        if (is_numeric($articleTenantId) && (int) $articleTenantId > 0) {
            return (int) $articleTenantId;
        }

        return (int) config('tenancy.default_tenant_id', 1);
    }
}
