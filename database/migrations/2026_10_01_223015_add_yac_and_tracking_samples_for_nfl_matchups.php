<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->decimal('yards_after_catch', 7, 2)->nullable();
        });
        Schema::create('nfl_matchup_tracking_samples', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('game_id');
            $table->unsignedBigInteger('team_id');
            $table->unsignedSmallInteger('season');
            $table->string('metric', 32);
            $table->string('player_id', 64);
            $table->decimal('value', 12, 6)->nullable();
            $table->unsignedSmallInteger('sample_size')->nullable();
            $table->timestamp('observed_at');
            $table->unique(['game_id', 'team_id', 'metric', 'player_id'], 'nfl_tracking_game_player_metric_unique');
            $table->index('season');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfl_matchup_tracking_samples');
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->dropColumn('yards_after_catch');
        });
    }
};
