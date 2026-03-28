<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['nba_team_metrics', 'wnba_team_metrics'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'injury_total_adjustment')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('injury_total_adjustment', 8, 3)
                    ->nullable()
                    ->after('injury_adjusted_team_rating');
            });
        }
    }

    public function down(): void
    {
        foreach (['nba_team_metrics', 'wnba_team_metrics'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'injury_total_adjustment')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('injury_total_adjustment');
            });
        }
    }
};
