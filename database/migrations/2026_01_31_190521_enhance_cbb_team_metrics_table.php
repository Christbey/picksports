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
        Schema::table('cbb_team_metrics', function (Blueprint $table) {
            // Minimum games tracking
            $table->integer('games_played')->default(0)->after('strength_of_schedule');
            $table->boolean('meets_minimum')->default(false)->after('games_played');

            // Adjusted metrics (opponent-adjusted)
            $table->decimal('adj_offensive_efficiency', 6, 2)->nullable()->after('meets_minimum');
            $table->decimal('adj_defensive_efficiency', 6, 2)->nullable()->after('adj_offensive_efficiency');
            $table->decimal('adj_net_rating', 6, 2)->nullable()->after('adj_defensive_efficiency');
            $table->decimal('adj_tempo', 5, 2)->nullable()->after('adj_net_rating');

            // Rolling window metrics (last 10 games)
            $table->decimal('rolling_offensive_efficiency', 6, 2)->nullable()->after('adj_tempo');
            $table->decimal('rolling_defensive_efficiency', 6, 2)->nullable()->after('rolling_offensive_efficiency');
            $table->decimal('rolling_net_rating', 6, 2)->nullable()->after('rolling_defensive_efficiency');
            $table->decimal('rolling_tempo', 5, 2)->nullable()->after('rolling_net_rating');
            $table->integer('rolling_games_count')->default(0)->after('rolling_tempo');

            // Home/Away splits
            $table->decimal('home_offensive_efficiency', 6, 2)->nullable()->after('rolling_games_count');
            $table->decimal('home_defensive_efficiency', 6, 2)->nullable()->after('home_offensive_efficiency');
            $table->decimal('away_offensive_efficiency', 6, 2)->nullable()->after('home_defensive_efficiency');
            $table->decimal('away_defensive_efficiency', 6, 2)->nullable()->after('away_offensive_efficiency');
            $table->integer('home_games')->default(0)->after('away_defensive_efficiency');
            $table->integer('away_games')->default(0)->after('home_games');

            // Possession coefficient tracking
            $table->decimal('possession_coefficient', 4, 3)->default(0.440)->after('away_games');
            $table->integer('iteration_count')->nullable()->after('possession_coefficient');

            // Add indexes for performance
            $table->index('meets_minimum');
            $table->index('games_played');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cbb_team_metrics', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['meets_minimum']);
            $table->dropIndex(['games_played']);

            // Drop all added columns
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
