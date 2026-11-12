<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 校验当前数据库连接是否真的受 RLS 约束。
 *
 * PostgreSQL 的超级用户与 BYPASSRLS 角色会绕过行级安全；若应用继续用这类角色连接，
 * 策略虽然存在但不会生效。本命令把这一部署前提显式暴露出来。
 */
class TenantRlsCheckCommand extends Command
{
    protected $signature = 'tenant:rls-check';

    protected $description = '检查数据库连接是否会绕过 PostgreSQL 行级安全（RLS）';

    public function handle(): int
    {
        try {
            $driver = DB::connection()->getDriverName();
        } catch (Throwable $e) {
            $this->error('数据库连接失败：'.$e->getMessage());

            return self::FAILURE;
        }

        if ($driver !== 'pgsql') {
            $this->warn('当前驱动为 '.$driver.'，RLS 仅在 PostgreSQL 下生效；生产环境请使用 PostgreSQL。');

            return self::SUCCESS;
        }

        $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

        $isSuper = $this->asBool($role->rolsuper ?? false);
        $bypass = $this->asBool($role->rolbypassrls ?? false);

        $tables = (array) config('tenancy.tables', []);
        $rows = DB::select(
            "select relname, relrowsecurity, relforcerowsecurity from pg_class where relkind = 'r' and relname = any(?)",
            ['{'.implode(',', $tables).'}']
        );
        $missing = [];
        foreach ($rows as $row) {
            if (! $this->asBool($row->relrowsecurity) || ! $this->asBool($row->relforcerowsecurity)) {
                $missing[] = $row->relname;
            }
        }

        $this->line('数据库角色：'.($role->name ?? 'unknown'));
        $this->line('超级用户：'.($isSuper ? '是' : '否'));
        $this->line('BYPASSRLS：'.($bypass ? '是' : '否'));
        $this->line('已覆盖表数量：'.count($rows).' / '.count($tables));

        if ($isSuper || $bypass) {
            $this->error('当前角色会绕过 RLS，租户隔离只剩应用层作用域。');
            $this->line('请为应用创建非超级用户、非 BYPASSRLS 的独立角色，并仅用超级用户执行迁移。');

            return self::FAILURE;
        }

        if ($missing !== []) {
            $this->error('以下表未启用或未强制 RLS：'.implode(', ', array_slice($missing, 0, 10)));

            return self::FAILURE;
        }

        $this->info('RLS 已生效。');

        return self::SUCCESS;
    }

    private function asBool(mixed $value): bool
    {
        return in_array($value, [true, 't', 'true', 1, '1'], true);
    }
}
