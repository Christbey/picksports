<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfb_game_context_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained('cfb_games')->cascadeOnDelete();
            $table->foreignId('home_team_id')->constrained('cfb_teams')->cascadeOnDelete();
            $table->foreignId('away_team_id')->constrained('cfb_teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('season');
            $table->unsignedTinyInteger('week')->nullable();

            $table->decimal('player_availability_spread_adjustment', 6, 3)->default(0);
            $table->decimal('player_availability_total_adjustment', 6, 3)->default(0);
            $table->decimal('home_player_availability_score', 6, 3)->nullable();
            $table->decimal('away_player_availability_score', 6, 3)->nullable();
            $table->decimal('home_qb_availability_score', 6, 3)->nullable();
            $table->decimal('away_qb_availability_score', 6, 3)->nullable();
            $table->json('player_availability_payload')->nullable();

            $table->decimal('weather_spread_adjustment', 6, 3)->default(0);
            $table->decimal('weather_total_adjustment', 6, 3)->default(0);
            $table->decimal('temperature_f', 6, 2)->nullable();
            $table->decimal('wind_speed_mph', 6, 2)->nullable();
            $table->decimal('wind_gust_mph', 6, 2)->nullable();
            $table->decimal('precipitation_inches', 6, 3)->nullable();
            $table->string('weather_condition', 80)->nullable();
            $table->json('weather_payload')->nullable();

            $table->decimal('rating_consensus_spread_adjustment', 6, 3)->default(0);
            $table->decimal('home_rating_consensus', 8, 3)->nullable();
            $table->decimal('away_rating_consensus', 8, 3)->nullable();
            $table->json('rating_consensus_payload')->nullable();

            $table->decimal('explosiveness_spread_adjustment', 6, 3)->default(0);
            $table->decimal('explosiveness_total_adjustment', 6, 3)->default(0);
            $table->decimal('home_explosiveness_score', 8, 3)->nullable();
            $table->decimal('away_explosiveness_score', 8, 3)->nullable();
            $table->json('explosiveness_payload')->nullable();

            $table->decimal('line_qb_spread_adjustment', 6, 3)->default(0);
            $table->decimal('home_line_qb_score', 8, 3)->nullable();
            $table->decimal('away_line_qb_score', 8, 3)->nullable();
            $table->json('line_qb_payload')->nullable();

            $table->decimal('market_movement_spread_adjustment', 6, 3)->default(0);
            $table->decimal('market_confidence_penalty', 6, 3)->default(0);
            $table->decimal('opening_home_spread', 6, 2)->nullable();
            $table->decimal('current_home_spread', 6, 2)->nullable();
            $table->decimal('closing_home_spread', 6, 2)->nullable();
            $table->decimal('consensus_home_spread', 6, 2)->nullable();
            $table->json('market_movement_payload')->nullable();

            $table->decimal('schedule_context_spread_adjustment', 6, 3)->default(0);
            $table->decimal('schedule_context_total_adjustment', 6, 3)->default(0);
            $table->decimal('schedule_confidence_penalty', 6, 3)->default(0);
            $table->unsignedSmallInteger('home_rest_days')->nullable();
            $table->unsignedSmallInteger('away_rest_days')->nullable();
            $table->json('schedule_context_payload')->nullable();

            $table->decimal('scheme_spread_adjustment', 6, 3)->default(0);
            $table->decimal('scheme_total_adjustment', 6, 3)->default(0);
            $table->decimal('scheme_confidence_penalty', 6, 3)->default(0);
            $table->decimal('home_scheme_change_score', 6, 3)->nullable();
            $table->decimal('away_scheme_change_score', 6, 3)->nullable();
            $table->json('scheme_payload')->nullable();

            $table->decimal('special_teams_spread_adjustment', 6, 3)->default(0);
            $table->decimal('special_teams_total_adjustment', 6, 3)->default(0);
            $table->decimal('home_special_teams_score', 8, 3)->nullable();
            $table->decimal('away_special_teams_score', 8, 3)->nullable();
            $table->json('special_teams_payload')->nullable();

            $table->json('signal_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique('game_id');
            $table->index(['season', 'week']);
            $table->index(['home_team_id', 'season']);
            $table->index(['away_team_id', 'season']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cfb_game_context_signals');
    }
};
