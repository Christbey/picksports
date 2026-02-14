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
        Schema::create('cfb_elo_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('cfb_teams')->onDelete('cascade');
            $table->integer('season');
            $table->integer('week');
            $table->string('season_type', 20);
            $table->decimal('elo_rating', 8, 2);
            $table->timestamps();

            $table->unique(['team_id', 'season', 'week', 'season_type']);
            $table->index(['season', 'week']);
            $table->index('elo_rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfb_elo_ratings');
    }
};
