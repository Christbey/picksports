<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfb_teams', function (Blueprint $table) {
            if (! Schema::hasColumn('cfb_teams', 'cfbd_team_id')) {
                $table->unsignedInteger('cfbd_team_id')->nullable()->after('espn_id')->index();
            }
        });

        Schema::table('cfb_team_metrics', function (Blueprint $table) {
            if (! Schema::hasColumn('cfb_team_metrics', 'fpi')) {
                $table->decimal('fpi', 8, 2)->nullable()->after('losses');
            }
            if (! Schema::hasColumn('cfb_team_metrics', 'cfbd_wepa_offense')) {
                $table->decimal('cfbd_wepa_offense', 10, 4)->nullable()->after('rest_travel_fatigue');
            }
            if (! Schema::hasColumn('cfb_team_metrics', 'cfbd_wepa_defense')) {
                $table->decimal('cfbd_wepa_defense', 10, 4)->nullable()->after('cfbd_wepa_offense');
            }
            if (! Schema::hasColumn('cfb_team_metrics', 'cfbd_wepa_net')) {
                $table->decimal('cfbd_wepa_net', 10, 4)->nullable()->after('cfbd_wepa_defense');
            }
            if (! Schema::hasColumn('cfb_team_metrics', 'cfbd_wepa_payload')) {
                $table->json('cfbd_wepa_payload')->nullable()->after('cfbd_wepa_net');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cfb_team_metrics', function (Blueprint $table) {
            $drops = [];

            foreach (['fpi', 'cfbd_wepa_offense', 'cfbd_wepa_defense', 'cfbd_wepa_net', 'cfbd_wepa_payload'] as $column) {
                if (Schema::hasColumn('cfb_team_metrics', $column)) {
                    $drops[] = $column;
                }
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });

        Schema::table('cfb_teams', function (Blueprint $table) {
            if (Schema::hasColumn('cfb_teams', 'cfbd_team_id')) {
                $table->dropColumn('cfbd_team_id');
            }
        });
    }
};
