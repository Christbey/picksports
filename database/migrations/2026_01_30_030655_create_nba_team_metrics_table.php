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
        Schema::create('nba_team_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('nba_teams')->onDelete('cascade');
            $table->integer('season');
            $table->decimal('offensive_efficiency', 6, 2)->nullable();
            $table->decimal('defensive_efficiency', 6, 2)->nullable();
            $table->decimal('net_rating', 6, 2)->nullable();
            $table->decimal('tempo', 5, 2)->nullable();
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
        Schema::dropIfExists('nba_team_metrics');
    }
};
