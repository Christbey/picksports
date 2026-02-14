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
        Schema::create('wcbb_player_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('wcbb_players')->onDelete('cascade');
            $table->foreignId('game_id')->constrained('wcbb_games')->onDelete('cascade');
            $table->foreignId('team_id')->constrained('wcbb_teams')->onDelete('cascade');
            $table->string('minutes_played', 10)->nullable();
            $table->integer('field_goals_made')->nullable();
            $table->integer('field_goals_attempted')->nullable();
            $table->integer('three_point_made')->nullable();
            $table->integer('three_point_attempted')->nullable();
            $table->integer('free_throws_made')->nullable();
            $table->integer('free_throws_attempted')->nullable();
            $table->integer('rebounds_offensive')->nullable();
            $table->integer('rebounds_defensive')->nullable();
            $table->integer('rebounds_total')->nullable();
            $table->integer('assists')->nullable();
            $table->integer('turnovers')->nullable();
            $table->integer('steals')->nullable();
            $table->integer('blocks')->nullable();
            $table->integer('fouls')->nullable();
            $table->integer('points')->nullable();
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
        Schema::dropIfExists('wcbb_player_stats');
    }
};
