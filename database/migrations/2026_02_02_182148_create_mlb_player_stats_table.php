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
        Schema::create('mlb_player_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('mlb_players')->onDelete('cascade');
            $table->foreignId('game_id')->constrained('mlb_games')->onDelete('cascade');
            $table->foreignId('team_id')->constrained('mlb_teams')->onDelete('cascade');
            $table->enum('stat_type', ['batting', 'pitching', 'fielding'])->default('batting');

            // Batting stats
            $table->integer('at_bats')->nullable();
            $table->integer('runs')->nullable();
            $table->integer('hits')->nullable();
            $table->integer('doubles')->nullable();
            $table->integer('triples')->nullable();
            $table->integer('home_runs')->nullable();
            $table->integer('rbis')->nullable();
            $table->integer('walks')->nullable();
            $table->integer('strikeouts')->nullable();
            $table->integer('stolen_bases')->nullable();
            $table->integer('caught_stealing')->nullable();
            $table->decimal('batting_average', 5, 3)->nullable();
            $table->decimal('on_base_percentage', 5, 3)->nullable();
            $table->decimal('slugging_percentage', 5, 3)->nullable();

            // Pitching stats
            $table->string('innings_pitched')->nullable();
            $table->integer('hits_allowed')->nullable();
            $table->integer('runs_allowed')->nullable();
            $table->integer('earned_runs')->nullable();
            $table->integer('walks_allowed')->nullable();
            $table->integer('strikeouts_pitched')->nullable();
            $table->integer('home_runs_allowed')->nullable();
            $table->decimal('era', 5, 2)->nullable();
            $table->integer('pitches_thrown')->nullable();
            $table->integer('pitch_count')->nullable();

            // Fielding stats
            $table->integer('putouts')->nullable();
            $table->integer('assists')->nullable();
            $table->integer('errors')->nullable();

            $table->timestamps();

            $table->unique(['player_id', 'game_id', 'stat_type']);
            $table->index('game_id');
            $table->index('team_id');
            $table->index('stat_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlb_player_stats');
    }
};
