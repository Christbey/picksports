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
        Schema::table('wcbb_team_metrics', function (Blueprint $table) {
            // Core tracking columns
            $table->unsignedInteger('games_played')->nullable()->after('strength_of_schedule');
            $table->boolean('meets_minimum')->default(false)->after('games_played');

            // Adjusted efficiency metrics (opponent-adjusted)
            $table->decimal('adj_offensive_efficiency', 8, 2)->nullable()->after('meets_minimum');
            $table->decimal('adj_defensive_efficiency', 8, 2)->nullable()->after('adj_offensive_efficiency');
            $table->decimal('adj_net_rating', 8, 2)->nullable()->after('adj_defensive_efficiency');
            $table->decimal('adj_tempo', 8, 2)->nullable()->after('adj_net_rating');

            // Rolling window metrics (recent form)
            $table->decimal('rolling_offensive_efficiency', 8, 2)->nullable()->after('adj_tempo');
            $table->decimal('rolling_defensive_efficiency', 8, 2)->nullable()->after('rolling_offensive_efficiency');
            $table->decimal('rolling_net_rating', 8, 2)->nullable()->after('rolling_defensive_efficiency');
            $table->decimal('rolling_tempo', 8, 2)->nullable()->after('rolling_net_rating');
            $table->unsignedInteger('rolling_games_count')->nullable()->after('rolling_tempo');

            // Home/away splits
            $table->decimal('home_offensive_efficiency', 8, 2)->nullable()->after('rolling_games_count');
            $table->decimal('home_defensive_efficiency', 8, 2)->nullable()->after('home_offensive_efficiency');
            $table->decimal('away_offensive_efficiency', 8, 2)->nullable()->after('home_defensive_efficiency');
            $table->decimal('away_defensive_efficiency', 8, 2)->nullable()->after('away_offensive_efficiency');
            $table->unsignedInteger('home_games')->nullable()->after('away_defensive_efficiency');
            $table->unsignedInteger('away_games')->nullable()->after('home_games');

            // Calculation metadata
            $table->decimal('possession_coefficient', 4, 2)->nullable()->after('away_games');
            $table->unsignedInteger('iteration_count')->nullable()->after('possession_coefficient');

            // Indexes
            $table->index('games_played');
            $table->index('meets_minimum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wcbb_team_metrics', function (Blueprint $table) {
            $table->dropIndex(['games_played']);
            $table->dropIndex(['meets_minimum']);

            $table->dropColumn([
                'games_played',
                'meets_minimum',
                'adj_offensive_efficiency',
                'adj_defensive_efficiency',
                'adj_net_rating',
                'adj_tempo',
                'rolling_offensive_efficiency',
                'rolling_defensive_efficiency',
                'rolling_net_rating',
                'rolling_tempo',
                'rolling_games_count',
                'home_offensive_efficiency',
                'home_defensive_efficiency',
                'away_offensive_efficiency',
                'away_defensive_efficiency',
                'home_games',
                'away_games',
                'possession_coefficient',
                'iteration_count',
            ]);
        });
    }
};
