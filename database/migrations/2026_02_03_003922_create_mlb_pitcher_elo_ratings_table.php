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
        Schema::create('mlb_pitcher_elo_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('mlb_players')->onDelete('cascade');
            $table->foreignId('game_id')->nullable()->constrained('mlb_games')->onDelete('cascade');
            $table->integer('season');
            $table->date('date')->nullable();
            $table->decimal('elo_rating', 8, 2);
            $table->decimal('elo_change', 10, 1)->nullable();
            $table->integer('games_started')->default(0);
            $table->timestamps();

            $table->index(['player_id', 'season', 'date']);
            $table->index(['season', 'date']);
            $table->index('elo_rating');
            $table->index('games_started');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlb_pitcher_elo_ratings');
    }
};
