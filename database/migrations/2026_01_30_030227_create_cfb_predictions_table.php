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
        Schema::create('cfb_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->unique()->constrained('cfb_games')->onDelete('cascade');
            $table->decimal('home_elo', 8, 2)->nullable();
            $table->decimal('away_elo', 8, 2)->nullable();
            $table->decimal('home_fpi', 8, 2)->nullable();
            $table->decimal('away_fpi', 8, 2)->nullable();
            $table->decimal('predicted_spread', 5, 2)->nullable();
            $table->decimal('win_probability', 5, 2)->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->timestamps();

            $table->index('confidence_score');
            $table->index('predicted_spread');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfb_predictions');
    }
};
