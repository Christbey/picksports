<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mlb_games', function (Blueprint $table) {
            $table->string('actual_home_pitcher_espn_id', 50)->nullable()->after('probable_away_pitcher_espn_id');
            $table->string('actual_away_pitcher_espn_id', 50)->nullable()->after('actual_home_pitcher_espn_id');
            $table->json('starting_pitcher_confirmation_metadata')->nullable()->after('pitcher_projection_generated_at');
            $table->timestamp('starting_pitchers_confirmed_at')->nullable()->after('starting_pitcher_confirmation_metadata');

            $table->index('actual_home_pitcher_espn_id');
            $table->index('actual_away_pitcher_espn_id');
        });
    }

    public function down(): void
    {
        Schema::table('mlb_games', function (Blueprint $table) {
            $table->dropIndex(['actual_home_pitcher_espn_id']);
            $table->dropIndex(['actual_away_pitcher_espn_id']);
            $table->dropColumn([
                'actual_home_pitcher_espn_id',
                'actual_away_pitcher_espn_id',
                'starting_pitcher_confirmation_metadata',
                'starting_pitchers_confirmed_at',
            ]);
        });
    }
};
