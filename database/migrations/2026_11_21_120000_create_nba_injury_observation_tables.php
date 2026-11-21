<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nba_player_injury_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('nba_teams');
            $table->string('provider', 32);
            $table->string('source_type', 32);
            $table->text('source_url');
            $table->string('normalizer_version', 64);
            $table->string('snapshot_hash', 64);
            $table->string('payload_hash', 64);
            $table->string('material_hash', 64);
            $table->string('outcome', 24);
            $table->unsignedInteger('entry_count');
            $table->json('raw_payload');
            $table->json('issues');
            $table->timestamp('created_at');
            $table->unique(['team_id', 'snapshot_hash'], 'nba_injury_snapshot_identity');
            $table->index(['team_id', 'created_at']);
        });
        Schema::create('nba_player_injury_snapshot_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('nba_player_injury_snapshots');
            // Retain the observed identity even if a current-roster player is later removed.
            $table->unsignedBigInteger('player_id')->nullable();
            $table->unsignedInteger('position');
            $table->string('espn_athlete_id', 100)->nullable();
            $table->string('injury_key', 120)->nullable();
            $table->string('espn_injury_id', 100)->nullable();
            $table->string('status', 100)->nullable();
            $table->text('detail')->nullable();
            $table->string('type', 100)->nullable();
            $table->date('injury_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('source_time_field', 32)->nullable();
            $table->text('source_time_raw')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('issues');
            $table->json('raw_payload');
            $table->timestamp('created_at');
            $table->unique(['snapshot_id', 'position']);
        });
        Schema::create('nba_player_injury_observations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('observation_uuid')->unique();
            $table->foreignId('team_id')->constrained('nba_teams');
            $table->foreignId('snapshot_id')->constrained('nba_player_injury_snapshots');
            $table->string('outcome', 24);
            $table->timestamp('started_at');
            $table->timestamp('completed_at');
            $table->timestamp('observed_at')->nullable();
            $table->boolean('cache_hit');
            $table->json('fetch_metadata');
            $table->json('issues');
            $table->index(['team_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nba_player_injury_observations');
        Schema::dropIfExists('nba_player_injury_snapshot_entries');
        Schema::dropIfExists('nba_player_injury_snapshots');
    }
};
