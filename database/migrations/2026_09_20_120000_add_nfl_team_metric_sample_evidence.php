<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_team_metrics', function (Blueprint $table) {
            // Unknown until recalculated; never backfill old rows with invented zero ties.
            $table->unsignedInteger('ties')->nullable();
            $table->unsignedInteger('games_played')->nullable();
            $table->json('sample_sizes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nfl_team_metrics', fn (Blueprint $table) => $table->dropColumn(['ties', 'games_played', 'sample_sizes']));
    }
};
