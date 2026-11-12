<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 创建应用运行时数据库角色（非超级用户、NOBYPASSRLS）。
 *
 * 背景：`geo_user` 是超级用户且 `rolbypassrls=true`，RLS 策略对它完全不生效。
 * 迁移/初始化继续使用高权限角色，应用（web/queue/scheduler/reverb）改用本迁移创建的角色，
 * 连接账号由 `DB_RUNTIME_USERNAME` / `DB_RUNTIME_PASSWORD` 指定。
 *
 * 幂等：角色已存在时只校正属性与授权，便于存量库重复部署。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('tenancy.runtime_role.name', 'geoflow_app');
        $password = (string) config('tenancy.runtime_role.password', '');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $role) !== 1) {
            $this->warn('忽略非法的运行时角色名：'.$role);

            return;
        }

        if (! $this->canManageRoles()) {
            $this->warn('当前数据库账号没有 CREATEROLE 权限，跳过运行时角色创建：'.$role);

            return;
        }

        if (! $this->roleExists($role)) {
            DB::statement('CREATE ROLE "'.$role.'" LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE');
        }

        if ($password !== '') {
            DB::statement('ALTER ROLE "'.$role.'" WITH PASSWORD \''.str_replace('\'', '\'\'', $password).'\'');
        }

        // 即使角色是手工预建的，也强制收紧属性，避免误配成超级用户/BYPASSRLS。
        DB::statement('ALTER ROLE "'.$role.'" NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE');

        $owner = $this->currentUser();

        DB::statement('GRANT USAGE ON SCHEMA public TO "'.$role.'"');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO "'.$role.'"');
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO "'.$role.'"');
        DB::statement('GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO "'.$role.'"');

        // 后续迁移新建的表/序列默认授权给运行时角色。
        DB::statement(
            'ALTER DEFAULT PRIVILEGES FOR ROLE "'.$owner.'" IN SCHEMA public '
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "'.$role.'"'
        );
        DB::statement(
            'ALTER DEFAULT PRIVILEGES FOR ROLE "'.$owner.'" IN SCHEMA public '
            .'GRANT USAGE, SELECT ON SEQUENCES TO "'.$role.'"'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $role = (string) config('tenancy.runtime_role.name', 'geoflow_app');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $role) !== 1 || ! $this->roleExists($role)) {
            return;
        }

        try {
            $owner = $this->currentUser();

            DB::statement(
                'ALTER DEFAULT PRIVILEGES FOR ROLE "'.$owner.'" IN SCHEMA public '
                .'REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM "'.$role.'"'
            );
            DB::statement(
                'ALTER DEFAULT PRIVILEGES FOR ROLE "'.$owner.'" IN SCHEMA public '
                .'REVOKE USAGE, SELECT ON SEQUENCES FROM "'.$role.'"'
            );
            DB::statement('DROP OWNED BY "'.$role.'"');
            DB::statement('DROP ROLE IF EXISTS "'.$role.'"');
        } catch (Throwable) {
            // 角色授权是集群级记录，其他库仍引用时无法删除；保留角色即可，重复部署会再次校正。
        }
    }

    private function canManageRoles(): bool
    {
        $row = DB::selectOne(
            'SELECT rolsuper OR rolcreaterole AS allowed FROM pg_roles WHERE rolname = current_user'
        );

        return (bool) ($row->allowed ?? false);
    }

    private function roleExists(string $role): bool
    {
        return DB::selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [$role]) !== null;
    }

    private function currentUser(): string
    {
        $row = DB::selectOne('SELECT current_user AS name');

        return (string) $row->name;
    }

    private function warn(string $message): void
    {
        fwrite(STDERR, '[tenancy] '.$message.PHP_EOL);
    }
};
