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
        Schema::create('cfb_team_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('cfb_teams')->onDelete('cascade');
            $table->foreignId('game_id')->constrained('cfb_games')->onDelete('cascade');
            $table->enum('team_type', ['home', 'away']);
            $table->integer('total_yards')->nullable();
            $table->integer('passing_yards')->nullable();
            $table->integer('passing_completions')->nullable();
            $table->integer('passing_attempts')->nullable();
            $table->integer('passing_touchdowns')->nullable();
            $table->integer('interceptions')->nullable();
            $table->integer('rushing_yards')->nullable();
            $table->integer('rushing_attempts')->nullable();
            $table->integer('rushing_touchdowns')->nullable();
            $table->integer('fumbles')->nullable();
            $table->integer('fumbles_lost')->nullable();
            $table->integer('sacks_allowed')->nullable();
            $table->integer('first_downs')->nullable();
            $table->integer('third_down_conversions')->nullable();
            $table->integer('third_down_attempts')->nullable();
            $table->integer('fourth_down_conversions')->nullable();
            $table->integer('fourth_down_attempts')->nullable();
            $table->integer('red_zone_attempts')->nullable();
            $table->integer('red_zone_scores')->nullable();
            $table->integer('penalties')->nullable();
            $table->integer('penalty_yards')->nullable();
            $table->string('time_of_possession', 10)->nullable();
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
        Schema::dropIfExists('cfb_team_stats');
    }
};
