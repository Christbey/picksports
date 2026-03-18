<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cbb_tournament_forecasts')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM cbb_tournament_forecasts'))
            ->pluck('Key_name')
            ->unique()
            ->flip();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) use ($indexes) {
            if (! $indexes->has('cbb_tournament_forecasts_team_id_index')) {
                $table->index('team_id', 'cbb_tournament_forecasts_team_id_index');
            }

            if ($indexes->has('cbb_tournament_forecasts_team_id_season_unique')) {
                $table->dropUnique('cbb_tournament_forecasts_team_id_season_unique');
            }

            if ($indexes->has('cbb_tf_team_season_uniq')) {
                $table->dropUnique('cbb_tf_team_season_uniq');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cbb_tournament_forecasts')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM cbb_tournament_forecasts'))
            ->pluck('Key_name')
            ->unique()
            ->flip();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) use ($indexes) {
            if ($indexes->has('cbb_tournament_forecasts_team_id_index')) {
                $table->dropIndex('cbb_tournament_forecasts_team_id_index');
            }

            if (! $indexes->has('cbb_tf_snapshot_team_uniq') && ! $indexes->has('cbb_tournament_forecasts_team_id_season_unique')) {
                $table->unique(['team_id', 'season'], 'cbb_tournament_forecasts_team_id_season_unique');
            }
        });
    }
};
