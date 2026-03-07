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
        Schema::create('odds_api_player_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('sport')->index();
            $table->string('odds_api_player_name')->index();
            $table->string('espn_player_name')->nullable()->index();
            $table->timestamps();

            $table->unique(['sport', 'odds_api_player_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('odds_api_player_mappings');
    }
};
