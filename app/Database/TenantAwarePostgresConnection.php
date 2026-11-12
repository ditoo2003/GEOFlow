<?php

namespace App\Database;

use App\Tenancy\TenantContext;
use Illuminate\Database\PostgresConnection;

/**
 * 感知租户上下文的 PostgreSQL 连接。
 *
 * `app.tenant_id` / `app.tenant_bypass` 是会话级变量，连接断开重连后会丢失；
 * 若任务在长连接里继续查询，RLS 会把它当成"未设置上下文"而返回空结果（fail-closed 但不正确）。
 * 这里在 `reconnect()` 与最外层事务回滚后，把内存中的租户上下文重新写回会话变量。
 */
class TenantAwarePostgresConnection extends PostgresConnection
{
    public function reconnect(): void
    {
        parent::reconnect();

        $this->syncTenantSessionVariables();
    }

    /**
     * `DB::reconnect()` 走的是 DatabaseManager 的 PDO 替换路径（setPdo），不会调用 reconnect()。
     *
     * @param  \PDO|\Closure|null  $pdo
     * @return $this
     */
    public function setPdo($pdo)
    {
        parent::setPdo($pdo);

        if ($pdo !== null) {
            $this->syncTenantSessionVariables();
        }

        return $this;
    }

    /**
     * 事务回滚会撤销事务内对会话变量的修改；回到最外层后按内存上下文重新同步。
     *
     * @param  int|null  $toLevel
     * @return void
     */
    public function rollBack($toLevel = null)
    {
        $result = parent::rollBack($toLevel);

        if ($this->transactions === 0) {
            $this->syncTenantSessionVariables();
        }

        return $result;
    }

    private function syncTenantSessionVariables(): void
    {
        app(TenantContext::class)->syncSessionVariables($this);
    }
}
