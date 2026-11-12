<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 租户主表与成员关系表，并写入存量默认租户（id=1，slug 固定 default）。
 *
 * slug 会被外部引用，改名不改 slug。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenants')) {
            Schema::create('tenants', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('slug', 80)->unique();
                $table->string('status', 20)->default('active');
                $table->string('plan_code', 60)->nullable();
                $table->string('isolation_mode', 20)->default('shared');
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tenant_memberships')) {
            Schema::create('tenant_memberships', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('admin_id');
                $table->string('role', 30)->default('admin');
                $table->timestamps();

                $table->unique(['tenant_id', 'admin_id'], 'tenant_memberships_tenant_admin_unique');
                $table->index('admin_id', 'tenant_memberships_admin_index');
            });
        }

        $defaultId = (int) config('tenancy.default_tenant_id', 1);
        $defaultSlug = (string) config('tenancy.default_tenant.slug', 'default');
        $defaultName = (string) config('tenancy.default_tenant.name', '默认租户');

        $exists = DB::table('tenants')->where('id', $defaultId)->exists();
        if (! $exists) {
            DB::table('tenants')->insert([
                'id' => $defaultId,
                'name' => $defaultName,
                'slug' => $defaultSlug,
                'status' => 'active',
                'isolation_mode' => 'shared',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (DB::getDriverName() === 'pgsql') {
            // 显式写入 id=1 后同步序列，避免下一个租户主键冲突。
            DB::statement("SELECT setval(pg_get_serial_sequence('tenants', 'id'), GREATEST((SELECT COALESCE(MAX(id), 1) FROM tenants), 1))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
    }
};
