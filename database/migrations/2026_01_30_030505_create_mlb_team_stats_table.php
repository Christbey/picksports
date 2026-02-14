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
        Schema::create('mlb_team_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('mlb_teams')->onDelete('cascade');
            $table->foreignId('game_id')->constrained('mlb_games')->onDelete('cascade');
            $table->enum('team_type', ['home', 'away']);

            // Batting stats
            $table->integer('runs')->nullable();
            $table->integer('hits')->nullable();
            $table->integer('errors')->nullable();
            $table->integer('at_bats')->nullable();
            $table->integer('doubles')->nullable();
            $table->integer('triples')->nullable();
            $table->integer('home_runs')->nullable();
            $table->integer('rbis')->nullable();
            $table->integer('walks')->nullable();
            $table->integer('strikeouts')->nullable();
            $table->integer('stolen_bases')->nullable();
            $table->integer('left_on_base')->nullable();
            $table->decimal('batting_average', 5, 3)->nullable();

            // Pitching stats
            $table->integer('pitchers_used')->nullable();
            $table->string('innings_pitched')->nullable();
            $table->integer('hits_allowed')->nullable();
            $table->integer('runs_allowed')->nullable();
            $table->integer('earned_runs')->nullable();
            $table->integer('walks_allowed')->nullable();
            $table->integer('strikeouts_pitched')->nullable();
            $table->integer('home_runs_allowed')->nullable();
            $table->integer('total_pitches')->nullable();
            $table->decimal('era', 5, 2)->nullable();

            // Fielding stats
            $table->integer('putouts')->nullable();
            $table->integer('assists')->nullable();
            $table->integer('double_plays')->nullable();
            $table->integer('passed_balls')->nullable();

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
        Schema::dropIfExists('mlb_team_stats');
    }
};
