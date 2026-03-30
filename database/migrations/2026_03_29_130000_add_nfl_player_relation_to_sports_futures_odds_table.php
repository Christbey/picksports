<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->foreignId('nfl_player_id')
                ->nullable()
                ->after('nfl_team_id')
                ->constrained('nfl_players')
                ->nullOnDelete();

            $table->index(['sport', 'season', 'nfl_player_id', 'market_key'], 'sports_futures_odds_nfl_player_market_index');
        });
    }

    public function down(): void
    {
        Schema::table('sports_futures_odds', function (Blueprint $table) {
            $table->dropIndex('sports_futures_odds_nfl_player_market_index');
            $table->dropConstrainedForeignId('nfl_player_id');
        });
    }
};
