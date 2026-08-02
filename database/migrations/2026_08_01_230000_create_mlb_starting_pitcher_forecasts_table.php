<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mlb_starting_pitcher_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('mlb_games')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('mlb_teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('season')->index();
            $table->enum('side', ['home', 'away']);
            $table->string('forecast_hash', 64)->unique();
            $table->string('model_version', 50);
            $table->string('prediction_source', 50)->default('rotation_projection');
            $table->string('predicted_pitcher_espn_id', 50);
            $table->decimal('confidence', 6, 5)->nullable();
            $table->decimal('predicted_pitcher_rating', 7, 2)->nullable();
            $table->string('predicted_rating_source', 50)->nullable();
            $table->json('evidence');
            $table->timestamp('forecasted_at');
            $table->timestamp('game_start_at')->nullable();
            $table->boolean('known_before_game_start')->default(false);
            $table->string('actual_pitcher_espn_id', 50)->nullable();
            $table->decimal('actual_pitcher_rating', 7, 2)->nullable();
            $table->string('actual_rating_source', 50)->nullable();
            $table->string('confirmation_source', 50)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->boolean('starter_changed')->nullable();
            $table->decimal('confidence_error', 8, 6)->nullable();
            $table->decimal('brier_score', 8, 6)->nullable();
            $table->decimal('log_loss', 10, 6)->nullable();
            $table->decimal('rating_difference', 8, 2)->nullable();
            $table->string('grade', 20)->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'side', 'forecasted_at'], 'mlb_starter_forecast_game_side_idx');
            $table->index(['season', 'known_before_game_start', 'graded_at'], 'mlb_starter_forecast_grade_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mlb_starting_pitcher_forecasts');
    }
};
