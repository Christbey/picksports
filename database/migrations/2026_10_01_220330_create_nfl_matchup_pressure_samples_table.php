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
        Schema::create('nfl_matchup_pressure_samples', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('opponent_id');
            $table->unsignedSmallInteger('season');
            $table->string('gsis_id', 32);
            $table->string('pfr_id', 50);
            $table->unsignedSmallInteger('pressures')->nullable();
            $table->unsignedSmallInteger('sacks')->nullable();
            $table->decimal('pressure_rate', 7, 5)->nullable();
            $table->timestamp('observed_at');
            $table->unique(['game_id', 'team_id', 'gsis_id'], 'nfl_pressure_game_player_unique');
            $table->index('season');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfl_matchup_pressure_samples');
    }
};
