<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->ulid('token_family_id')->unique();
            $table->string('device_name', 120);
            $table->string('platform', 20);
            $table->char('device_identifier_hash', 64)->nullable();
            $table->json('abilities');
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at'], 'device_sessions_user_revoked_idx');
            $table->index(['access_token_expires_at', 'revoked_at'], 'device_sessions_access_expiry_idx');
            $table->index(['device_identifier_hash', 'revoked_at'], 'device_sessions_identifier_idx');
        });

        Schema::create('device_session_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_session_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('replaced_by_token_id')
                ->nullable()
                ->constrained('device_session_refresh_tokens')
                ->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 40)->nullable();
            $table->timestamps();

            $table->index(
                ['device_session_id', 'revoked_at', 'expires_at'],
                'device_refresh_session_status_idx',
            );
        });

        Schema::create('device_push_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_session_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 10);
            $table->char('token_hash', 64);
            $table->text('device_token');
            $table->string('environment', 20)->nullable();
            $table->timestamp('last_registered_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'token_hash'], 'device_push_provider_token_unique');
            $table->index(['device_session_id', 'revoked_at'], 'device_push_session_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_registrations');
        Schema::dropIfExists('device_session_refresh_tokens');
        Schema::dropIfExists('device_sessions');
    }
};
