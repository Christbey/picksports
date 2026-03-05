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
        $tables = [
            'nba_team_metrics',
            'wnba_team_metrics',
            'cbb_team_metrics',
            'wcbb_team_metrics',
            'nfl_team_metrics',
            'cfb_team_metrics',
            'mlb_team_metrics',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'recent_form_rating')) {
                    $blueprint->decimal('recent_form_rating', 7, 3)->nullable()->after('strength_of_schedule');
                }
                if (! Schema::hasColumn($table, 'injury_adjusted_team_rating')) {
                    $blueprint->decimal('injury_adjusted_team_rating', 8, 3)->nullable()->after('recent_form_rating');
                }
                if (! Schema::hasColumn($table, 'rest_travel_fatigue')) {
                    $blueprint->decimal('rest_travel_fatigue', 6, 3)->nullable()->after('injury_adjusted_team_rating');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'nba_team_metrics',
            'wnba_team_metrics',
            'cbb_team_metrics',
            'wcbb_team_metrics',
            'nfl_team_metrics',
            'cfb_team_metrics',
            'mlb_team_metrics',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $drops = [];
                if (Schema::hasColumn($table, 'recent_form_rating')) {
                    $drops[] = 'recent_form_rating';
                }
                if (Schema::hasColumn($table, 'injury_adjusted_team_rating')) {
                    $drops[] = 'injury_adjusted_team_rating';
                }
                if (Schema::hasColumn($table, 'rest_travel_fatigue')) {
                    $drops[] = 'rest_travel_fatigue';
                }

                if ($drops !== []) {
                    $blueprint->dropColumn($drops);
                }
            });
        }
    }
};
