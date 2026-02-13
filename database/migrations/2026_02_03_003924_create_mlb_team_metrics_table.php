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
        Schema::create('mlb_team_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('mlb_teams')->onDelete('cascade');
            $table->integer('season');
            $table->decimal('offensive_rating', 6, 2)->nullable();
            $table->decimal('pitching_rating', 6, 2)->nullable();
            $table->decimal('defensive_rating', 6, 2)->nullable();
            $table->decimal('runs_per_game', 5, 2)->nullable();
            $table->decimal('runs_allowed_per_game', 5, 2)->nullable();
            $table->decimal('batting_average', 5, 3)->nullable();
            $table->decimal('team_era', 5, 2)->nullable();
            $table->decimal('strength_of_schedule', 6, 2)->nullable();
            $table->date('calculation_date')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'season']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mlb_team_metrics');
    }
};
