<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 自助注册的两张平台级表（不属于租户隔离清单）。
 *
 * - `registration_invitations`：邀请制注册链接，存 token 哈希，单次使用、可设有效期；
 * - `registration_signups`：公开注册的待验证申请，存密码哈希与验证 token 哈希。
 *
 * 两种模式共用同一条租户创建链路，这里只负责注册自身的前置数据。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('registration_invitations')) {
            Schema::create('registration_invitations', function (Blueprint $table): void {
                $table->id();
                $table->string('token_hash', 64)->unique();
                $table->string('email')->nullable();
                $table->string('tenant_name')->nullable();
                $table->string('note', 255)->nullable();
                $table->unsignedBigInteger('created_by_admin_id')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('used_at')->nullable();
                $table->unsignedBigInteger('used_tenant_id')->nullable();
                $table->timestamps();

                $table->index('email');
                $table->index('expires_at');
            });
        }

        if (! Schema::hasTable('registration_signups')) {
            Schema::create('registration_signups', function (Blueprint $table): void {
                $table->id();
                $table->string('email')->unique();
                $table->string('name', 120);
                $table->string('tenant_name', 120);
                $table->string('password');
                $table->string('verification_token_hash', 64)->unique();
                $table->unsignedInteger('sent_count')->default(1);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('locale', 20)->nullable();
                $table->timestamps();

                $table->index('expires_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_signups');
        Schema::dropIfExists('registration_invitations');
    }
};
