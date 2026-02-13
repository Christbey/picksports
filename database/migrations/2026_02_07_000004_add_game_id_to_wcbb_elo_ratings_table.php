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
        Schema::table('wcbb_elo_ratings', function (Blueprint $table) {
            $table->foreignId('game_id')->nullable()->after('team_id')->constrained('wcbb_games')->onDelete('cascade');
            $table->decimal('elo_change', 10, 1)->nullable()->after('elo_rating');

            // Drop old unique constraint and add new one
            $table->dropUnique(['team_id', 'season', 'game_date']);

            // Add new unique constraint on team_id and game_id
            $table->unique(['team_id', 'game_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wcbb_elo_ratings', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->dropUnique(['team_id', 'game_id']);
            $table->dropColumn(['game_id', 'elo_change']);

            $table->unique(['team_id', 'season', 'game_date']);
        });
    }
};
