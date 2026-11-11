<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 调用账本补观测列：延迟、协议、thinking 形态、实际输出上限、重试序号。
     *
     * 全部可空，存量事件与未填充的调用点无需迁移数据。
     */
    public function up(): void
    {
        Schema::table('ai_model_usage_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('duration_ms')->nullable()->after('total_tokens');
            $table->string('protocol', 40)->nullable()->after('duration_ms');
            $table->string('thinking_mode', 40)->nullable()->after('protocol');
            $table->unsignedBigInteger('max_tokens_applied')->nullable()->after('thinking_mode');
            $table->unsignedSmallInteger('attempt')->nullable()->after('max_tokens_applied');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_usage_events', function (Blueprint $table): void {
            $table->dropColumn([
                'duration_ms',
                'protocol',
                'thinking_mode',
                'max_tokens_applied',
                'attempt',
            ]);
        });
    }
};
