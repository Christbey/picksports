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
        Schema::table('cfb_elo_ratings', function (Blueprint $table) {
            $table->foreignId('game_id')->nullable()->after('team_id')->constrained('cfb_games')->onDelete('cascade');
            $table->date('date')->nullable()->after('season_type');

            // Drop old unique constraint and add new one
            $table->dropUnique(['team_id', 'season', 'week', 'season_type']);

            // Make week nullable for backward compatibility
            $table->integer('week')->nullable()->change();

            // Add new unique constraint on team_id and game_id
            $table->unique(['team_id', 'game_id']);

            // Add useful indexes
            $table->index(['team_id', 'season', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cfb_elo_ratings', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->dropUnique(['team_id', 'game_id']);
            $table->dropIndex(['team_id', 'season', 'date']);
            $table->dropColumn(['game_id', 'date']);

            $table->integer('week')->nullable(false)->change();
            $table->unique(['team_id', 'season', 'week', 'season_type']);
        });
    }
};
