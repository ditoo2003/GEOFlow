<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_usage_attempt_starts', function (Blueprint $table): void {
            $table->string('protocol', 40)->nullable()->after('source_id');
            $table->string('thinking_mode', 40)->nullable()->after('protocol');
            $table->unsignedInteger('max_tokens_applied')->nullable()->after('thinking_mode');
            $table->unsignedSmallInteger('attempt')->nullable()->after('max_tokens_applied');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_usage_attempt_starts', function (Blueprint $table): void {
            $table->dropColumn(['protocol', 'thinking_mode', 'max_tokens_applied', 'attempt']);
        });
    }
};
