<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_odds_snapshots', function (Blueprint $table) {
            $table->index(['sport', 'game_table', 'game_id', 'captured_at', 'id'], 'game_odds_snapshots_ordered_game_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('game_odds_snapshots', function (Blueprint $table) {
            $table->dropIndex('game_odds_snapshots_ordered_game_lookup');
        });
    }
};
