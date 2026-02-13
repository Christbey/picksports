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
        Schema::create('cfb_fpi_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('cfb_teams')->onDelete('cascade');
            $table->integer('season');
            $table->integer('week');
            $table->float('fpi')->nullable();
            $table->float('offense')->nullable();
            $table->float('defense')->nullable();
            $table->float('special_teams')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'season', 'week']);
            $table->index(['season', 'week']);
            $table->index('fpi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cfb_fpi_ratings');
    }
};
