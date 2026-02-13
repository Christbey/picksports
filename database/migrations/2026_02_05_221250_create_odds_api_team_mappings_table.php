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
        Schema::create('odds_api_team_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('espn_team_name')->index();
            $table->string('odds_api_team_name')->index();
            $table->string('sport')->default('basketball_ncaab')->index();
            $table->timestamps();

            $table->unique(['espn_team_name', 'sport']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('odds_api_team_mappings');
    }
};
