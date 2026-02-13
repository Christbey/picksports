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
        Schema::create('nba_elo_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('nba_teams')->onDelete('cascade');
            $table->integer('season');
            $table->integer('week');
            $table->decimal('elo_rating', 8, 2);
            $table->timestamps();

            $table->unique(['team_id', 'season', 'week']);
            $table->index(['season', 'week']);
            $table->index('elo_rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nba_elo_ratings');
    }
};
