<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cfb_team_metrics')) {
            return;
        }

        Schema::table('cfb_team_metrics', function (Blueprint $table): void {
            if (! Schema::hasColumn('cfb_team_metrics', 'rating_consensus')) {
                $table->decimal('rating_consensus', 8, 3)->nullable()->after('resume_rating');
            }

            if (! Schema::hasColumn('cfb_team_metrics', 'rating_consensus_sources')) {
                $table->json('rating_consensus_sources')->nullable()->after('rating_consensus');
            }

            foreach ([
                'offensive_success_rate',
                'defensive_success_rate',
                'net_success_rate',
                'offensive_explosiveness',
                'defensive_explosiveness',
                'net_explosiveness',
                'offensive_havoc_rate',
                'defensive_havoc_rate',
                'net_havoc_rate',
                'offensive_line_yards',
                'offensive_stuff_rate',
                'offensive_sack_rate',
                'offensive_line_rating',
                'qb_environment_rating',
                'defensive_front_rating',
            ] as $column) {
                if (! Schema::hasColumn('cfb_team_metrics', $column)) {
                    $table->decimal($column, 8, 4)->nullable()->after('rating_consensus_sources');
                }
            }

            if (! Schema::hasColumn('cfb_team_metrics', 'cfbd_advanced_payload')) {
                $table->json('cfbd_advanced_payload')->nullable()->after('qb_environment_rating');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cfb_team_metrics')) {
            return;
        }

        Schema::table('cfb_team_metrics', function (Blueprint $table): void {
            $drops = [];

            foreach ([
                'rating_consensus',
                'rating_consensus_sources',
                'offensive_success_rate',
                'defensive_success_rate',
                'net_success_rate',
                'offensive_explosiveness',
                'defensive_explosiveness',
                'net_explosiveness',
                'offensive_havoc_rate',
                'defensive_havoc_rate',
                'net_havoc_rate',
                'offensive_line_yards',
                'offensive_stuff_rate',
                'offensive_sack_rate',
                'offensive_line_rating',
                'qb_environment_rating',
                'defensive_front_rating',
                'cfbd_advanced_payload',
            ] as $column) {
                if (Schema::hasColumn('cfb_team_metrics', $column)) {
                    $drops[] = $column;
                }
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
