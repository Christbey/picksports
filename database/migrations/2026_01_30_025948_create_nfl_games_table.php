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
        Schema::create('nfl_games', function (Blueprint $table) {
            $table->id();
            $table->string('espn_event_id', 50)->unique();
            $table->string('espn_uid', 100)->nullable();
            $table->integer('season');
            $table->integer('week');
            $table->string('season_type', 20);
            $table->date('game_date');
            $table->time('game_time');
            $table->string('name')->nullable();
            $table->string('short_name')->nullable();
            $table->foreignId('home_team_id')->constrained('nfl_teams')->onDelete('cascade');
            $table->foreignId('away_team_id')->constrained('nfl_teams')->onDelete('cascade');
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->json('home_linescores')->nullable();
            $table->json('away_linescores')->nullable();
            $table->string('status', 50)->nullable();
            $table->integer('period')->nullable();
            $table->string('game_clock', 20)->nullable();
            $table->boolean('neutral_site')->default(false);
            $table->string('venue_name')->nullable();
            $table->string('venue_city', 100)->nullable();
            $table->string('venue_state', 50)->nullable();
            $table->json('broadcast_networks')->nullable();
            $table->timestamps();

            $table->index(['season', 'week']);
            $table->index('game_date');
            $table->index('status');
            $table->index('home_team_id');
            $table->index('away_team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfl_games');
    }
};
