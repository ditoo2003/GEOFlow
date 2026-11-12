<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->string('provider_key', 32)->nullable()->after('api_url');
            $table->string('protocol', 40)->nullable()->after('provider_key');
            $table->json('capabilities')->nullable()->after('protocol');
            $table->integer('context_length')->nullable()->after('capabilities');
            $table->json('timeout_profile')->nullable()->after('context_length');
            $table->string('thinking_mode', 16)->nullable()->after('timeout_profile');
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_key',
                'protocol',
                'capabilities',
                'context_length',
                'timeout_profile',
                'thinking_mode',
            ]);
        });
    }
};
