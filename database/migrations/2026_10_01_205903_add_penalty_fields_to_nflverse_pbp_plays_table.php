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
            $table->boolean('is_penalty')->nullable();
            $table->string('penalty_type', 100)->nullable();
            $table->unsignedBigInteger('penalty_team_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nflverse_pbp_plays', function (Blueprint $table): void {
            $table->dropColumn(['is_penalty', 'penalty_type', 'penalty_team_id']);
        });
    }
};
