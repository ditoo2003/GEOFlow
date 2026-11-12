<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 P0 隔离的 A 类业务表补 `tenant_id`。
 *
 * 存量数据通过列默认值直接归入默认租户（PostgreSQL 11+ 与 SQLite 均为元数据操作，不需要停机回填）；
 * `platform_tables` 允许 NULL，表示平台级模板/系统模型池。
 *
 * 唯一键的租户化在 `2026_11_12_100500` 中单独执行。
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaultId = (int) config('tenancy.default_tenant_id', 1);
        $platformTables = (array) config('tenancy.platform_tables', []);
        $tables = (array) config('tenancy.tables', []);

        foreach ($tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $defaultId, $platformTables): void {
                $column = $table->unsignedBigInteger('tenant_id')->index($tableName.'_tenant_id_index');

                if (in_array($tableName, $platformTables, true)) {
                    $column->nullable();
                } else {
                    $column->default($defaultId);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse((array) config('tenancy.tables', [])) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex($tableName.'_tenant_id_index');
                $table->dropColumn('tenant_id');
            });
        }
    }
};
