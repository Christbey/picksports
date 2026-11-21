<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nba_player_injury_observations', function (Blueprint $table): void {
            $table->unsignedBigInteger('acquisition_sequence')->nullable();
            $table->boolean('projection_applied')->default(false);
            $table->unique(['team_id', 'acquisition_sequence'], 'nba_injury_acquisition_identity');
        });
        Schema::create('nba_injury_team_states', function (Blueprint $table): void {
            $table->foreignId('team_id')->primary()->constrained('nba_teams');
            $table->unsignedBigInteger('issued_sequence')->default(0);
            $table->foreignId('latest_observation_id')->nullable()->constrained('nba_player_injury_observations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nba_injury_team_states');
        Schema::table('nba_player_injury_observations', function (Blueprint $table): void {
            $table->dropUnique('nba_injury_acquisition_identity');
            $table->dropColumn(['acquisition_sequence', 'projection_applied']);
        });
    }
};
