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
        Schema::table('cbb_elo_ratings', function (Blueprint $table) {
            $table->foreignId('game_id')->nullable()->after('team_id')->constrained('cbb_games')->onDelete('cascade');
            $table->date('date')->nullable()->after('season');
            $table->decimal('elo_change', 10, 1)->nullable()->after('elo_rating');

            // Drop old unique constraint
            $table->dropUnique(['team_id', 'season', 'week']);

            // Make week nullable for backward compatibility
            $table->integer('week')->nullable()->change();

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
        Schema::table('cbb_elo_ratings', function (Blueprint $table) {
            $table->dropForeign(['game_id']);
            $table->dropUnique(['team_id', 'game_id']);
            $table->dropIndex(['team_id', 'season', 'date']);
            $table->dropIndex(['date']);
            $table->dropColumn(['game_id', 'date', 'elo_change']);

            $table->integer('week')->nullable(false)->change();
            $table->unique(['team_id', 'season', 'week']);
        });
    }
};
