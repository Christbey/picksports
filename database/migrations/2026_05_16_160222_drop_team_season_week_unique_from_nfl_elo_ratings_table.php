<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_elo_ratings', function (Blueprint $table) {
            $table->dropUnique('nfl_elo_ratings_team_id_season_week_unique');
            $table->index(['team_id', 'season', 'week'], 'nfl_elo_ratings_team_id_season_week_index');
        });
    }

    public function down(): void
    {
        Schema::table('nfl_elo_ratings', function (Blueprint $table) {
            $table->dropIndex('nfl_elo_ratings_team_id_season_week_index');
            $table->unique(['team_id', 'season', 'week'], 'nfl_elo_ratings_team_id_season_week_unique');
        });
    }
};
