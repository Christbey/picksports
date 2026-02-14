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
        Schema::create('wcbb_team_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('wcbb_teams')->onDelete('cascade');
            $table->foreignId('game_id')->constrained('wcbb_games')->onDelete('cascade');
            $table->enum('team_type', ['home', 'away']);
            $table->integer('field_goals_made')->nullable();
            $table->integer('field_goals_attempted')->nullable();
            $table->integer('three_point_made')->nullable();
            $table->integer('three_point_attempted')->nullable();
            $table->integer('free_throws_made')->nullable();
            $table->integer('free_throws_attempted')->nullable();
            $table->integer('rebounds')->nullable();
            $table->integer('offensive_rebounds')->nullable();
            $table->integer('defensive_rebounds')->nullable();
            $table->integer('assists')->nullable();
            $table->integer('turnovers')->nullable();
            $table->integer('steals')->nullable();
            $table->integer('blocks')->nullable();
            $table->integer('fouls')->nullable();
            $table->integer('points')->nullable();
            $table->float('possessions')->nullable();
            $table->integer('fast_break_points')->nullable();
            $table->integer('points_in_paint')->nullable();
            $table->integer('second_chance_points')->nullable();
            $table->integer('bench_points')->nullable();
            $table->integer('biggest_lead')->nullable();
            $table->integer('times_tied')->nullable();
            $table->integer('lead_changes')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'game_id']);
            $table->index('game_id');
            $table->index('team_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wcbb_team_stats');
    }
};
