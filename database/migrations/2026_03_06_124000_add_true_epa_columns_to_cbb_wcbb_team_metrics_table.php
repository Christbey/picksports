<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cbb_team_metrics', 'wcbb_team_metrics'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'offensive_true_epa_per_play')) {
                    $table->decimal('offensive_true_epa_per_play', 8, 3)
                        ->nullable()
                        ->after('iteration_count');
                }

                if (! Schema::hasColumn($tableName, 'defensive_true_epa_per_play')) {
                    $table->decimal('defensive_true_epa_per_play', 8, 3)
                        ->nullable()
                        ->after('offensive_true_epa_per_play');
                }

                if (! Schema::hasColumn($tableName, 'net_true_epa_per_play')) {
                    $table->decimal('net_true_epa_per_play', 8, 3)
                        ->nullable()
                        ->after('defensive_true_epa_per_play');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['cbb_team_metrics', 'wcbb_team_metrics'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['offensive_true_epa_per_play', 'defensive_true_epa_per_play', 'net_true_epa_per_play'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
