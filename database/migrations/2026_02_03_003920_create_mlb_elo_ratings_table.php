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
        Schema::create('mlb_elo_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('mlb_teams')->onDelete('cascade');
            $table->foreignId('game_id')->nullable()->constrained('mlb_games')->onDelete('cascade');
            $table->integer('season');
            $table->integer('week')->nullable();
            $table->date('date')->nullable();
            $table->decimal('elo_rating', 8, 2);
            $table->decimal('elo_change', 10, 1)->nullable();
            $table->timestamps();

            $table->index(['team_id', 'season', 'date']);
            $table->index(['season', 'week']);
            $table->index('elo_rating');
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlb_elo_ratings');
    }
};
