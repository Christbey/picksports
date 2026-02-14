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
        Schema::table('nba_elo_ratings', function (Blueprint $table) {
            // Add game_id column with foreign key
            $table->foreignId('game_id')->nullable()->after('team_id')->constrained('nba_games')->onDelete('cascade');

            // Add date and elo_change columns
            $table->date('date')->nullable()->after('season');
            $table->decimal('elo_change', 10, 1)->nullable()->after('elo_rating');

            // Add new unique constraint on team_id and game_id
            $table->unique(['team_id', 'game_id']);

            // Add useful indexes
            $table->index(['team_id', 'season', 'date']);
            $table->index('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nba_elo_ratings', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->dropUnique(['team_id', 'game_id']);
            $table->dropIndex(['team_id', 'season', 'date']);
            $table->dropIndex(['date']);
            $table->dropColumn(['game_id', 'date', 'elo_change']);
        });
    }
};
