<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_games', function (Blueprint $table) {
            $table->string('nflverse_game_id', 32)->nullable()->unique()->after('espn_uid');
            $table->string('home_qb_id', 64)->nullable()->after('away_score');
            $table->string('home_qb_name', 128)->nullable()->after('home_qb_id');
            $table->string('away_qb_id', 64)->nullable()->after('home_qb_name');
            $table->string('away_qb_name', 128)->nullable()->after('away_qb_id');
            $table->string('home_coach', 128)->nullable()->after('away_qb_name');
            $table->string('away_coach', 128)->nullable()->after('home_coach');
            $table->string('stadium_id', 64)->nullable()->after('venue_name');
            $table->string('roof', 64)->nullable()->after('stadium_id');
            $table->string('surface', 64)->nullable()->after('roof');
            $table->unsignedTinyInteger('home_rest')->nullable()->after('surface');
            $table->unsignedTinyInteger('away_rest')->nullable()->after('home_rest');
            $table->boolean('division_game')->nullable()->after('away_rest');

            $table->index(['season', 'season_type', 'week'], 'nfl_games_season_type_week_index');
            $table->index(['home_qb_id', 'away_qb_id'], 'nfl_games_qb_ids_index');
        });
    }

    public function down(): void
    {
        Schema::table('nfl_games', function (Blueprint $table) {
            $table->dropIndex('nfl_games_qb_ids_index');
            $table->dropIndex('nfl_games_season_type_week_index');
            $table->dropUnique(['nflverse_game_id']);
            $table->dropColumn([
                'nflverse_game_id',
                'home_qb_id',
                'home_qb_name',
                'away_qb_id',
                'away_qb_name',
                'home_coach',
                'away_coach',
                'stadium_id',
                'roof',
                'surface',
                'home_rest',
                'away_rest',
                'division_game',
            ]);
        });
    }
};
