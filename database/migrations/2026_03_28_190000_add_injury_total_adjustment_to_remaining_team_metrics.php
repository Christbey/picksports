<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'mlb_team_metrics',
            'nfl_team_metrics',
            'cfb_team_metrics',
            'cbb_team_metrics',
            'wcbb_team_metrics',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'injury_total_adjustment')) {
                    $table->decimal('injury_total_adjustment', 8, 3)->nullable()->after('injury_adjusted_team_rating');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'mlb_team_metrics',
            'nfl_team_metrics',
            'cfb_team_metrics',
            'cbb_team_metrics',
            'wcbb_team_metrics',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (Schema::hasColumn($tableName, 'injury_total_adjustment')) {
                    $table->dropColumn('injury_total_adjustment');
                }
            });
        }
    }
};
