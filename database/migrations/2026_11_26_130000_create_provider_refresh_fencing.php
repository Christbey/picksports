<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_refresh_heads', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 16);
            $table->string('sport', 8);
            $table->string('component', 16);
            $table->unsignedBigInteger('team_id');
            $table->unsignedSmallInteger('season_key');
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
            $table->unsignedBigInteger('latest_terminal_attempt_id')->nullable();
            $table->unsignedBigInteger('latest_applied_attempt_id')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unique(['provider', 'sport', 'component', 'team_id', 'season_key'], 'provider_refresh_scope_unique');
        });
        Schema::create('provider_refresh_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('head_id')->constrained('provider_refresh_heads', indexName: 'refresh_attempt_head_fk')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->char('owner_hash', 64);
            $table->string('team_provider_id', 64);
            $table->dateTime('started_at', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->string('outcome', 16)->nullable();
            $table->boolean('observed')->default(false);
            $table->boolean('reliable')->default(false);
            $table->unsignedInteger('row_count')->default(0);
            $table->char('source_fingerprint', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->foreignId('nfl_injury_snapshot_id')->nullable()->constrained('nfl_player_injury_snapshots', indexName: 'refresh_attempt_injury_snapshot_fk')->restrictOnDelete();
            $table->foreignId('nfl_depth_snapshot_id')->nullable()->constrained('nfl_depth_chart_snapshots', indexName: 'refresh_attempt_depth_snapshot_fk')->restrictOnDelete();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unique(['head_id', 'sequence'], 'provider_refresh_attempt_sequence_unique');
            $table->index(['outcome', 'expires_at', 'id'], 'provider_refresh_expiry_index');
        });
        Schema::table('provider_refresh_heads', function (Blueprint $table): void {
            $table->foreign('latest_terminal_attempt_id', 'refresh_head_terminal_fk')->references('id')->on('provider_refresh_attempts')->restrictOnDelete();
            $table->foreign('latest_applied_attempt_id', 'refresh_head_applied_fk')->references('id')->on('provider_refresh_attempts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        throw new LogicException('Provider refresh ordering and audit receipts are forward-only; ordinary rollback is refused.');
    }
};
