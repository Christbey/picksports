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
        Schema::create('nfl_player_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('nfl_players')->onDelete('cascade');
            $table->foreignId('game_id')->constrained('nfl_games')->onDelete('cascade');
            $table->foreignId('team_id')->constrained('nfl_teams')->onDelete('cascade');

            // Passing
            $table->integer('passing_completions')->nullable();
            $table->integer('passing_attempts')->nullable();
            $table->integer('passing_yards')->nullable();
            $table->integer('passing_touchdowns')->nullable();
            $table->integer('interceptions_thrown')->nullable();
            $table->integer('sacks_taken')->nullable();

            // Rushing
            $table->integer('rushing_attempts')->nullable();
            $table->integer('rushing_yards')->nullable();
            $table->integer('rushing_touchdowns')->nullable();
            $table->integer('rushing_long')->nullable();

            // Receiving
            $table->integer('receptions')->nullable();
            $table->integer('receiving_yards')->nullable();
            $table->integer('receiving_touchdowns')->nullable();
            $table->integer('receiving_targets')->nullable();
            $table->integer('receiving_long')->nullable();

            // Defense
            $table->integer('tackles_total')->nullable();
            $table->integer('tackles_solo')->nullable();
            $table->integer('tackles_assists')->nullable();
            $table->decimal('sacks', 3, 1)->nullable();
            $table->integer('interceptions')->nullable();
            $table->integer('passes_defended')->nullable();
            $table->integer('fumbles_forced')->nullable();
            $table->integer('fumbles_recovered')->nullable();

            // Kicking
            $table->integer('field_goals_made')->nullable();
            $table->integer('field_goals_attempted')->nullable();
            $table->integer('extra_points_made')->nullable();
            $table->integer('extra_points_attempted')->nullable();

            $table->timestamps();

            $table->unique(['player_id', 'game_id']);
            $table->index('game_id');
            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfl_player_stats');
    }
};
