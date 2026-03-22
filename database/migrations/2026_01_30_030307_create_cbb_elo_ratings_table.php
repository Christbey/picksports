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
        Schema::create('cbb_elo_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('cbb_teams')->onDelete('cascade');
            $table->foreignId('game_id')->nullable()->constrained('cbb_games')->onDelete('cascade');
            $table->integer('season');
            $table->date('date')->nullable();
            $table->integer('week')->nullable();
            $table->decimal('elo_rating', 8, 2);
            $table->decimal('elo_change', 10, 1)->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'game_id']);
            $table->index(['team_id', 'season', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cbb_elo_ratings');
    }
};
