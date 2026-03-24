<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_games', function (Blueprint $table) {
            if (! Schema::hasColumn('mlb_games', 'probable_home_pitcher_espn_id')) {
                $table->string('probable_home_pitcher_espn_id', 50)->nullable()->after('outs');
                $table->index('probable_home_pitcher_espn_id');
            }

            if (! Schema::hasColumn('mlb_games', 'probable_away_pitcher_espn_id')) {
                $table->string('probable_away_pitcher_espn_id', 50)->nullable()->after('probable_home_pitcher_espn_id');
                $table->index('probable_away_pitcher_espn_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('mlb_games', function (Blueprint $table) {
            if (Schema::hasColumn('mlb_games', 'probable_home_pitcher_espn_id')) {
                $table->dropIndex(['probable_home_pitcher_espn_id']);
                $table->dropColumn('probable_home_pitcher_espn_id');
            }

            if (Schema::hasColumn('mlb_games', 'probable_away_pitcher_espn_id')) {
                $table->dropIndex(['probable_away_pitcher_espn_id']);
                $table->dropColumn('probable_away_pitcher_espn_id');
            }
        });
    }
};
