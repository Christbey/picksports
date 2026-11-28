<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfbd_fpi_season_heads', function (Blueprint $table): void {
            $table->id();
            $table->integer('season')->unique();
            $table->unsignedSmallInteger('protocol_version');
            $table->char('target_fingerprint', 64);
            $table->string('activation_operator', 80);
            $table->string('activation_reason', 256);
            $table->string('drain_reference', 160);
            $table->string('source_review_reference', 160);
            $table->dateTime('activated_at', 6);
            $table->dateTime('drained_at', 6);
            $table->unsignedBigInteger('issued_sequence')->default(0);
            $table->unsignedBigInteger('latest_terminal_sequence')->default(0);
            $table->unsignedBigInteger('latest_applied_sequence')->default(0);
            $table->unsignedBigInteger('latest_cache_origin_sequence')->default(0);
            $table->unsignedBigInteger('latest_cache_origin_attempt_id')->nullable();
            $table->string('latest_cache_origin_key', 255)->nullable();
            $table->char('latest_cache_origin_sha256', 64)->nullable();
            $table->char('latest_cache_origin_activation_hash', 64)->nullable();
            $table->char('latest_cache_origin_source_hash', 64)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
        Schema::create('cfbd_fpi_season_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('head_id')->constrained('cfbd_fpi_season_heads')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->integer('week');
            $table->char('owner_hash', 64);
            $table->char('source_identity_hash', 64);
            $table->char('activation_identity_hash', 64);
            $table->char('accepted_source_identity_hash', 64)->nullable();
            $table->string('source_cache_key', 255)->nullable();
            $table->dateTime('started_at', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->string('outcome', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->dateTime('source_observed_at', 6)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['head_id', 'sequence']);
            $table->index(['outcome', 'expires_at', 'id']);
        });
        Schema::table('cfbd_fpi_season_heads', function (Blueprint $table): void {
            $table->foreign('latest_cache_origin_attempt_id', 'cfbd_fpi_head_cache_origin_fk')->references('id')->on('cfbd_fpi_season_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('CFBD FPI acquisition order and audit receipts are forward-only.');
    }
};
