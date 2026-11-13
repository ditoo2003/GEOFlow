<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 原始回答留存 90 天的清理标记：清理只脱敏原始请求/回答，引用与提及保留用于聚合。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            $table->timestamp('raw_pruned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            $table->dropColumn('raw_pruned_at');
        });
    }
};
