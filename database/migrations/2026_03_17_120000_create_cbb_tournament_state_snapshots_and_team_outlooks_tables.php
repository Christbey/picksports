<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbb_tournament_state_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('season');
            $table->dateTime('as_of');
            $table->string('source', 32)->default('manual');
            $table->string('status', 32)->default('running');
            $table->foreignId('trigger_game_id')->nullable()->constrained('cbb_games')->nullOnDelete();
            $table->unsignedInteger('games_final_count')->default(0);
            $table->unsignedInteger('games_remaining_count')->default(0);
            $table->unsignedTinyInteger('field_size')->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['season', 'as_of'], 'cbb_tournament_state_snapshots_season_as_of_idx');
            $table->index(['season', 'status'], 'cbb_tournament_state_snapshots_season_status_idx');
        });

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->foreignId('snapshot_id')->nullable()->after('id')->constrained('cbb_tournament_state_snapshots')->nullOnDelete();
            $table->dateTime('as_of')->nullable()->after('season');
            $table->string('mode', 32)->default('baseline')->after('as_of');
            $table->string('region')->nullable()->after('mode');
            $table->unsignedTinyInteger('seed')->nullable()->after('region');
            $table->boolean('is_first_four')->default(false)->after('seed');
            $table->boolean('is_alive')->default(true)->after('is_first_four');
            $table->boolean('is_eliminated')->default(false)->after('is_alive');
            $table->string('reached_round')->nullable()->after('is_eliminated');
            $table->string('eliminated_round')->nullable()->after('reached_round');
            $table->unsignedInteger('games_final_count')->default(0)->after('simulation_runs');
            $table->decimal('round_of_32_probability', 6, 5)->default(0)->after('games_final_count');
            $table->decimal('sweet_16_probability', 6, 5)->default(0)->after('round_of_32_probability');
            $table->decimal('elite_8_probability', 6, 5)->default(0)->after('sweet_16_probability');
        });

        $this->backfillBaselineSnapshots();
        $this->replaceForecastIndexes();
    }

    public function down(): void
    {
        $this->restoreForecastIndexes();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('snapshot_id');
            $table->dropColumn([
                'as_of',
                'mode',
                'region',
                'seed',
                'is_first_four',
                'is_alive',
                'is_eliminated',
                'reached_round',
                'eliminated_round',
                'games_final_count',
                'round_of_32_probability',
                'sweet_16_probability',
                'elite_8_probability',
            ]);
        });

        Schema::dropIfExists('cbb_tournament_state_snapshots');
    }

    private function backfillBaselineSnapshots(): void
    {
        $seasons = DB::table('cbb_tournament_forecasts')
            ->select('season')
            ->distinct()
            ->pluck('season');

        foreach ($seasons as $season) {
            $snapshotId = DB::table('cbb_tournament_state_snapshots')->insertGetId([
                'season' => $season,
                'as_of' => now(),
                'source' => 'baseline_backfill',
                'status' => 'completed',
                'games_final_count' => 0,
                'games_remaining_count' => 0,
                'field_size' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('cbb_tournament_forecasts')
                ->where('season', $season)
                ->update([
                    'snapshot_id' => $snapshotId,
                    'as_of' => now(),
                    'mode' => 'baseline',
                ]);
        }
    }

    private function replaceForecastIndexes(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM cbb_tournament_forecasts'))
            ->pluck('Key_name')
            ->unique()
            ->flip();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) use ($indexes) {
            if ($indexes->has('cbb_tf_team_season_uniq')) {
                $table->dropUnique('cbb_tf_team_season_uniq');
            }
        });

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) {
            $table->unique(['snapshot_id', 'team_id'], 'cbb_tf_snapshot_team_uniq');
            $table->index(['season', 'mode', 'as_of'], 'cbb_tf_season_mode_asof_idx');
            $table->index(['snapshot_id', 'champion_probability'], 'cbb_tf_snapshot_champ_idx');
        });
    }

    private function restoreForecastIndexes(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM cbb_tournament_forecasts'))
            ->pluck('Key_name')
            ->unique()
            ->flip();

        Schema::table('cbb_tournament_forecasts', function (Blueprint $table) use ($indexes) {
            if ($indexes->has('cbb_tf_snapshot_team_uniq')) {
                $table->dropUnique('cbb_tf_snapshot_team_uniq');
            }
            if ($indexes->has('cbb_tf_season_mode_asof_idx')) {
                $table->dropIndex('cbb_tf_season_mode_asof_idx');
            }
            if ($indexes->has('cbb_tf_snapshot_champ_idx')) {
                $table->dropIndex('cbb_tf_snapshot_champ_idx');
            }

            $table->unique(['team_id', 'season'], 'cbb_tf_team_season_uniq');
        });
    }
};
