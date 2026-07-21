<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nflverse_pbp_plays', function (Blueprint $table) {
            $table->id();
            $table->string('nflverse_play_key', 64)->unique();
            $table->foreignId('nfl_game_id')->nullable()->constrained('nfl_games')->nullOnDelete();
            $table->string('nflverse_game_id', 32)->nullable();
            $table->string('play_id', 32)->nullable();
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedTinyInteger('week')->nullable();
            $table->string('season_type', 10)->nullable();
            $table->string('home_team', 10)->nullable();
            $table->string('away_team', 10)->nullable();
            $table->foreignId('possession_team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('possession_team', 10)->nullable();
            $table->foreignId('defense_team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('defense_team', 10)->nullable();
            $table->unsignedTinyInteger('quarter')->nullable();
            $table->unsignedTinyInteger('down')->nullable();
            $table->unsignedTinyInteger('yards_to_go')->nullable();
            $table->decimal('yardline_100', 5, 2)->nullable();
            $table->decimal('yards_gained', 6, 2)->nullable();
            $table->decimal('game_seconds_remaining', 7, 2)->nullable();
            $table->string('play_type', 50)->nullable();
            $table->text('description')->nullable();
            $table->decimal('epa', 10, 4)->nullable();
            $table->decimal('win_probability', 10, 6)->nullable();
            $table->decimal('win_probability_added', 10, 6)->nullable();
            $table->string('passer_player_id', 32)->nullable();
            $table->string('passer_player_name', 120)->nullable();
            $table->string('rusher_player_id', 32)->nullable();
            $table->string('rusher_player_name', 120)->nullable();
            $table->string('receiver_player_id', 32)->nullable();
            $table->string('receiver_player_name', 120)->nullable();
            $table->boolean('is_touchdown')->nullable();
            $table->boolean('is_interception')->nullable();
            $table->boolean('is_fumble_lost')->nullable();
            $table->boolean('is_sack')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['season', 'week']);
            $table->index(['nflverse_game_id', 'play_id']);
            $table->index(['possession_team', 'season', 'week']);
            $table->index(['defense_team', 'season', 'week']);
            $table->index('play_type');
        });

        Schema::create('nflverse_rosters', function (Blueprint $table) {
            $table->id();
            $table->string('nflverse_roster_key', 64)->unique();
            $table->unsignedSmallInteger('season')->nullable();
            $table->foreignId('team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('team', 10)->nullable();
            $table->string('gsis_id', 32)->nullable();
            $table->string('espn_id', 50)->nullable();
            $table->string('pfr_id', 50)->nullable();
            $table->string('full_name', 200)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('position', 20)->nullable();
            $table->string('jersey_number', 10)->nullable();
            $table->string('status', 50)->nullable();
            $table->unsignedTinyInteger('years_exp')->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('weight')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['season', 'team']);
            $table->index('gsis_id');
            $table->index('espn_id');
            $table->index('position');
        });

        Schema::create('nflverse_depth_charts', function (Blueprint $table) {
            $table->id();
            $table->string('nflverse_depth_chart_key', 64)->unique();
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedTinyInteger('week')->nullable();
            $table->string('season_type', 10)->nullable();
            $table->foreignId('team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('team', 10)->nullable();
            $table->string('gsis_id', 32)->nullable();
            $table->string('full_name', 200)->nullable();
            $table->string('position', 20)->nullable();
            $table->string('depth_team', 30)->nullable();
            $table->string('depth_position', 50)->nullable();
            $table->string('formation', 50)->nullable();
            $table->unsignedTinyInteger('depth_rank')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['season', 'week', 'team']);
            $table->index('gsis_id');
            $table->index(['team', 'depth_position']);
        });

        Schema::create('nflverse_injuries', function (Blueprint $table) {
            $table->id();
            $table->string('nflverse_injury_key', 64)->unique();
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedTinyInteger('week')->nullable();
            $table->string('season_type', 10)->nullable();
            $table->foreignId('team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('team', 10)->nullable();
            $table->string('gsis_id', 32)->nullable();
            $table->string('full_name', 200)->nullable();
            $table->string('position', 20)->nullable();
            $table->string('report_primary_injury', 255)->nullable();
            $table->string('report_status', 80)->nullable();
            $table->string('practice_status', 80)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['season', 'week', 'team']);
            $table->index('gsis_id');
            $table->index('report_status');
            $table->index('practice_status');
        });

        Schema::create('nflverse_weekly_player_stats', function (Blueprint $table) {
            $table->id();
            $table->string('nflverse_weekly_stat_key', 64)->unique();
            $table->foreignId('nfl_game_id')->nullable()->constrained('nfl_games')->nullOnDelete();
            $table->string('nflverse_game_id', 32)->nullable();
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedTinyInteger('week')->nullable();
            $table->string('season_type', 10)->nullable();
            $table->foreignId('team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('team', 10)->nullable();
            $table->foreignId('opponent_team_id')->nullable()->constrained('nfl_teams')->nullOnDelete();
            $table->string('opponent_team', 10)->nullable();
            $table->string('player_id', 32)->nullable();
            $table->string('player_name', 120)->nullable();
            $table->string('player_display_name', 200)->nullable();
            $table->string('position', 20)->nullable();
            $table->string('position_group', 30)->nullable();
            $table->unsignedSmallInteger('passing_attempts')->nullable();
            $table->integer('passing_yards')->nullable();
            $table->unsignedTinyInteger('passing_touchdowns')->nullable();
            $table->unsignedTinyInteger('interceptions_thrown')->nullable();
            $table->unsignedSmallInteger('rushing_attempts')->nullable();
            $table->integer('rushing_yards')->nullable();
            $table->unsignedTinyInteger('rushing_touchdowns')->nullable();
            $table->unsignedSmallInteger('targets')->nullable();
            $table->unsignedSmallInteger('receptions')->nullable();
            $table->integer('receiving_yards')->nullable();
            $table->unsignedTinyInteger('receiving_touchdowns')->nullable();
            $table->decimal('fantasy_points_ppr', 8, 2)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['season', 'week', 'team']);
            $table->index(['season', 'week', 'player_id']);
            $table->index('nflverse_game_id');
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nflverse_weekly_player_stats');
        Schema::dropIfExists('nflverse_injuries');
        Schema::dropIfExists('nflverse_depth_charts');
        Schema::dropIfExists('nflverse_rosters');
        Schema::dropIfExists('nflverse_pbp_plays');
    }
};
