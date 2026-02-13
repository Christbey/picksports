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
        Schema::create('cfb_team_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('cfb_teams')->onDelete('cascade');
            $table->unsignedSmallInteger('season');
            $table->decimal('offensive_rating', 5, 1)->nullable();
            $table->decimal('defensive_rating', 5, 1)->nullable();
            $table->decimal('net_rating', 5, 1)->nullable();
            $table->decimal('points_per_game', 4, 1)->nullable();
            $table->decimal('points_allowed_per_game', 4, 1)->nullable();
            $table->decimal('yards_per_game', 5, 1)->nullable();
            $table->decimal('yards_allowed_per_game', 5, 1)->nullable();
            $table->decimal('passing_yards_per_game', 5, 1)->nullable();
            $table->decimal('rushing_yards_per_game', 5, 1)->nullable();
            $table->decimal('turnover_differential', 4, 1)->nullable();
            $table->decimal('strength_of_schedule', 6, 3)->nullable();
            $table->date('calculation_date')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'season']);
            $table->index('season');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfb_team_metrics');
    }
};
