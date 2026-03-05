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
                if (! Schema::hasColumn($table, 'wins')) {
                    $blueprint->unsignedSmallInteger('wins')->nullable()->after('season');
                }
                if (! Schema::hasColumn($table, 'losses')) {
                    $blueprint->unsignedSmallInteger('losses')->nullable()->after('wins');
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
                if (Schema::hasColumn($table, 'wins')) {
                    $drops[] = 'wins';
                }
                if (Schema::hasColumn($table, 'losses')) {
                    $drops[] = 'losses';
                }

                if ($drops !== []) {
                    $blueprint->dropColumn($drops);
                }
            });
        }
    }
};
