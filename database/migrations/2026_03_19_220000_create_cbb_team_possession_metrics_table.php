<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbb_team_possession_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('cbb_teams')->cascadeOnDelete();
            $table->unsignedInteger('season');
            $table->date('as_of_date');
            $table->unsignedInteger('games_sampled')->default(0);
            $table->unsignedInteger('offensive_possessions')->default(0);
            $table->unsignedInteger('defensive_possessions')->default(0);
            $table->unsignedInteger('rolling_games_sampled')->default(0);
            $table->unsignedInteger('rolling_offensive_possessions')->default(0);
            $table->unsignedInteger('rolling_defensive_possessions')->default(0);
            $table->unsignedInteger('late_game_offensive_possessions')->default(0);
            $table->unsignedInteger('late_game_defensive_possessions')->default(0);
            $table->decimal('offensive_points_per_possession', 8, 3)->default(0);
            $table->decimal('defensive_points_per_possession_allowed', 8, 3)->default(0);
            $table->decimal('net_points_per_possession', 8, 3)->default(0);
            $table->decimal('rolling_offensive_points_per_possession', 8, 3)->default(0);
            $table->decimal('rolling_defensive_points_per_possession_allowed', 8, 3)->default(0);
            $table->decimal('rolling_net_points_per_possession', 8, 3)->default(0);
            $table->decimal('late_game_offensive_points_per_possession', 8, 3)->default(0);
            $table->decimal('late_game_defensive_points_per_possession_allowed', 8, 3)->default(0);
            $table->decimal('turnover_rate', 8, 3)->default(0);
            $table->decimal('forced_turnover_rate', 8, 3)->default(0);
            $table->decimal('free_throw_trip_rate', 8, 3)->default(0);
            $table->decimal('free_throw_rate_allowed', 8, 3)->default(0);
            $table->decimal('possessions_per_game', 8, 3)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'season', 'as_of_date'], 'cbb_team_possession_metrics_team_season_date_unique');
            $table->index(['season', 'as_of_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbb_team_possession_metrics');
    }
};
