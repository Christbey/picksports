<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_sessions', function (Blueprint $table): void {
            $table->string('native_policy_version', 32)->nullable();
            $table->unsignedBigInteger('access_expires_epoch')->nullable();
            $table->index(['native_policy_version', 'access_expires_epoch', 'id'], 'native_session_epoch_idx');
        });
        Schema::table('device_session_refresh_tokens', function (Blueprint $table): void {
            $table->string('native_policy_version', 32)->nullable();
            $table->unsignedBigInteger('expires_epoch')->nullable();
            // @expand-nullability Epoch policy retains the original TIMESTAMP column and attributes for legacy rows.
            $table->timestamp('expires_at')->nullable()->change();
            $table->index(['native_policy_version', 'expires_epoch', 'id'], 'native_refresh_epoch_idx');
        });
    }

    public function down(): void
    {
        throw new LogicException('Native credential policy and reuse history require a compatible forward repair; epoch markers cannot be removed.');
    }
};
