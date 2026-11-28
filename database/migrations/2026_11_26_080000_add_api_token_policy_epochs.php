<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('api_policy_version', 32)->nullable();
            $table->string('api_token_profile', 24)->nullable();
            $table->unsignedBigInteger('api_expires_epoch')->nullable();
            $table->index(['api_policy_version', 'api_expires_epoch', 'id'], 'pat_api_policy_expiry_idx');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('API token policy markers require a separate audited contract migration and credential invalidation; use a compatible forward repair.');
    }
};
