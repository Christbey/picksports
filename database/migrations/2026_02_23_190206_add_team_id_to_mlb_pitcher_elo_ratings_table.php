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
        Schema::table('mlb_pitcher_elo_ratings', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('player_id')->constrained('mlb_teams')->onDelete('set null');
            $table->index(['team_id', 'season', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mlb_pitcher_elo_ratings', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropIndex(['team_id', 'season', 'date']);
            $table->dropColumn('team_id');
        });
    }
};
