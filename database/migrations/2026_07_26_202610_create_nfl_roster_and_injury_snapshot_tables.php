<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nfl_depth_chart_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('snapshot_uuid')->unique();
            $table->foreignId('team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('espn_team_id', 50);
            $table->unsignedSmallInteger('season');
            $table->string('provider', 30)->default('espn');
            $table->timestamp('observed_at');
            $table->timestamp('source_updated_at')->nullable();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('entry_count')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(
                ['team_id', 'season', 'observed_at'],
                'nfl_depth_snapshots_team_season_observed_idx'
            );
            $table->index(
                ['espn_team_id', 'season', 'observed_at'],
                'nfl_depth_snapshots_espn_team_observed_idx'
            );
            $table->index('payload_hash', 'nfl_depth_snapshots_payload_hash_idx');
        });

        Schema::create('nfl_depth_chart_snapshot_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('nfl_depth_chart_snapshots')->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('nfl_players')->nullOnDelete();
            $table->string('espn_depth_chart_id', 100)->nullable();
            $table->string('depth_chart_name', 150)->nullable();
            $table->string('position_slot_key', 50);
            $table->string('position_espn_id', 50)->nullable();
            $table->string('position_code', 50)->nullable();
            $table->string('position_name', 150)->nullable();
            $table->string('position_display_name', 150)->nullable();
            $table->string('espn_athlete_id', 50)->nullable();
            $table->unsignedInteger('slot_order')->nullable();
            $table->unsignedInteger('depth_rank')->default(1);
            $table->boolean('is_starter')->default(false);
            $table->timestamp('observed_at');
            $table->timestamp('source_updated_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(
                ['snapshot_id', 'position_slot_key', 'depth_rank'],
                'nfl_depth_snapshot_entries_position_idx'
            );
            $table->index(
                ['espn_athlete_id', 'observed_at'],
                'nfl_depth_snapshot_entries_athlete_idx'
            );
        });

        Schema::create('nfl_player_injury_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('snapshot_uuid')->unique();
            $table->foreignId('team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('espn_team_id', 50);
            $table->string('provider', 30)->default('espn');
            $table->timestamp('observed_at');
            $table->timestamp('source_updated_at')->nullable();
            $table->char('payload_hash', 64);
            $table->unsignedInteger('entry_count')->default(0);
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(
                ['team_id', 'observed_at'],
                'nfl_injury_snapshots_team_observed_idx'
            );
            $table->index(
                ['espn_team_id', 'observed_at'],
                'nfl_injury_snapshots_espn_team_observed_idx'
            );
            $table->index('payload_hash', 'nfl_injury_snapshots_payload_hash_idx');
        });

        Schema::create('nfl_player_injury_snapshot_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('nfl_player_injury_snapshots')->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('nfl_players')->nullOnDelete();
            $table->string('espn_athlete_id', 50)->nullable();
            $table->string('injury_key', 120);
            $table->string('espn_injury_id', 100)->nullable();
            $table->string('status', 100)->nullable();
            $table->string('detail', 255)->nullable();
            $table->string('type', 100)->nullable();
            $table->date('injury_date')->nullable();
            $table->date('return_date')->nullable();
            $table->timestamp('observed_at');
            $table->timestamp('source_updated_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(
                ['snapshot_id', 'espn_athlete_id'],
                'nfl_injury_snapshot_entries_athlete_idx'
            );
            $table->index(
                ['espn_athlete_id', 'observed_at'],
                'nfl_injury_snapshot_entries_observed_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfl_player_injury_snapshot_entries');
        Schema::dropIfExists('nfl_player_injury_snapshots');
        Schema::dropIfExists('nfl_depth_chart_snapshot_entries');
        Schema::dropIfExists('nfl_depth_chart_snapshots');
    }
};
