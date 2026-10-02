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
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->string('fumbled_1_player_id', 32)->nullable();
            $table->string('fumbled_2_player_id', 32)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->dropColumn(['fumbled_1_player_id', 'fumbled_2_player_id']);
        });
    }
};
