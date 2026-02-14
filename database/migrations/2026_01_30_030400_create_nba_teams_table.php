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
        Schema::create('nba_teams', function (Blueprint $table) {
            $table->id();
            $table->string('espn_id', 50)->unique();
            $table->string('abbreviation', 10)->unique();
            $table->string('location', 100)->nullable();
            $table->string('name', 100)->nullable();
            $table->string('conference', 50)->nullable();
            $table->string('division', 50)->nullable();
            $table->string('color', 7)->nullable();
            $table->string('logo_url')->nullable();
            $table->integer('elo_rating')->default(1500);
            $table->timestamps();

            $table->index(['conference', 'division']);
            $table->index('abbreviation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nba_teams');
    }
};
