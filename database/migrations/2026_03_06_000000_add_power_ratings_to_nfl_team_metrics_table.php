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
        if (! Schema::hasTable('nfl_team_metrics')) {
            return;
        }

        Schema::table('nfl_team_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('nfl_team_metrics', 'predictive_rating')) {
                $table->decimal('predictive_rating', 8, 3)->nullable()->after('rest_travel_fatigue');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'home_rating')) {
                $table->decimal('home_rating', 8, 3)->nullable()->after('predictive_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'away_rating')) {
                $table->decimal('away_rating', 8, 3)->nullable()->after('home_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'home_advantage_rating')) {
                $table->decimal('home_advantage_rating', 8, 3)->nullable()->after('away_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'future_strength_of_schedule')) {
                $table->decimal('future_strength_of_schedule', 8, 3)->nullable()->after('home_advantage_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'season_strength_of_schedule')) {
                $table->decimal('season_strength_of_schedule', 8, 3)->nullable()->after('future_strength_of_schedule');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'strength_of_schedule_basic')) {
                $table->decimal('strength_of_schedule_basic', 8, 3)->nullable()->after('season_strength_of_schedule');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'in_division_strength_of_schedule')) {
                $table->decimal('in_division_strength_of_schedule', 8, 3)->nullable()->after('strength_of_schedule_basic');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'non_division_strength_of_schedule')) {
                $table->decimal('non_division_strength_of_schedule', 8, 3)->nullable()->after('in_division_strength_of_schedule');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'last_5_rating')) {
                $table->decimal('last_5_rating', 8, 3)->nullable()->after('non_division_strength_of_schedule');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'last_10_rating')) {
                $table->decimal('last_10_rating', 8, 3)->nullable()->after('last_5_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'in_division_rating')) {
                $table->decimal('in_division_rating', 8, 3)->nullable()->after('last_10_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'non_division_rating')) {
                $table->decimal('non_division_rating', 8, 3)->nullable()->after('in_division_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'luck_rating')) {
                $table->decimal('luck_rating', 8, 3)->nullable()->after('non_division_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'consistency_rating')) {
                $table->decimal('consistency_rating', 8, 3)->nullable()->after('luck_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'vs_1_to_5_rating')) {
                $table->decimal('vs_1_to_5_rating', 8, 3)->nullable()->after('consistency_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'vs_6_to_10_rating')) {
                $table->decimal('vs_6_to_10_rating', 8, 3)->nullable()->after('vs_1_to_5_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'vs_11_to_16_rating')) {
                $table->decimal('vs_11_to_16_rating', 8, 3)->nullable()->after('vs_6_to_10_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'vs_17_to_22_rating')) {
                $table->decimal('vs_17_to_22_rating', 8, 3)->nullable()->after('vs_11_to_16_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'vs_23_to_32_rating')) {
                $table->decimal('vs_23_to_32_rating', 8, 3)->nullable()->after('vs_17_to_22_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'first_half_rating')) {
                $table->decimal('first_half_rating', 8, 3)->nullable()->after('vs_23_to_32_rating');
            }
            if (! Schema::hasColumn('nfl_team_metrics', 'second_half_rating')) {
                $table->decimal('second_half_rating', 8, 3)->nullable()->after('first_half_rating');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('nfl_team_metrics')) {
            return;
        }

        Schema::table('nfl_team_metrics', function (Blueprint $table) {
            $drops = [];
            foreach ([
                'predictive_rating',
                'home_rating',
                'away_rating',
                'home_advantage_rating',
                'future_strength_of_schedule',
                'season_strength_of_schedule',
                'strength_of_schedule_basic',
                'in_division_strength_of_schedule',
                'non_division_strength_of_schedule',
                'last_5_rating',
                'last_10_rating',
                'in_division_rating',
                'non_division_rating',
                'luck_rating',
                'consistency_rating',
                'vs_1_to_5_rating',
                'vs_6_to_10_rating',
                'vs_11_to_16_rating',
                'vs_17_to_22_rating',
                'vs_23_to_32_rating',
                'first_half_rating',
                'second_half_rating',
            ] as $column) {
                if (Schema::hasColumn('nfl_team_metrics', $column)) {
                    $drops[] = $column;
                }
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
