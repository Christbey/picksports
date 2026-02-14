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
        Schema::create('mlb_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->unique()->constrained('mlb_games')->onDelete('cascade');
            $table->decimal('home_team_elo', 8, 2)->nullable();
            $table->decimal('away_team_elo', 8, 2)->nullable();
            $table->decimal('home_pitcher_elo', 8, 2)->nullable();
            $table->decimal('away_pitcher_elo', 8, 2)->nullable();
            $table->decimal('home_combined_elo', 8, 2)->nullable();
            $table->decimal('away_combined_elo', 8, 2)->nullable();
            $table->decimal('predicted_spread', 5, 2)->nullable();
            $table->decimal('predicted_total', 6, 2)->nullable();
            $table->decimal('win_probability', 5, 2)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->timestamps();

            $table->index('confidence_score');
            $table->index('predicted_spread');
            $table->index('win_probability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlb_predictions');
    }
};
