<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nba_team_metrics')) {
            return;
        }

        Schema::table('nba_team_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('nba_team_metrics', 'offensive_true_epa_per_play')) {
                $table->decimal('offensive_true_epa_per_play', 8, 3)
                    ->nullable()
                    ->after('rest_travel_fatigue');
            }

            if (! Schema::hasColumn('nba_team_metrics', 'defensive_true_epa_per_play')) {
                $table->decimal('defensive_true_epa_per_play', 8, 3)
                    ->nullable()
                    ->after('offensive_true_epa_per_play');
            }

            if (! Schema::hasColumn('nba_team_metrics', 'net_true_epa_per_play')) {
                $table->decimal('net_true_epa_per_play', 8, 3)
                    ->nullable()
                    ->after('defensive_true_epa_per_play');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nba_team_metrics')) {
            return;
        }

        Schema::table('nba_team_metrics', function (Blueprint $table) {
            foreach ([
                'offensive_true_epa_per_play',
                'defensive_true_epa_per_play',
                'net_true_epa_per_play',
            ] as $column) {
                if (Schema::hasColumn('nba_team_metrics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
