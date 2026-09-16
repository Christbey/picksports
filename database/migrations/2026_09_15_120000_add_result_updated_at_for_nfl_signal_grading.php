<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nfl_games', function (Blueprint $table): void {
            $table->timestamp('result_updated_at')
                ->nullable()
                ->after('away_score');
            $table->index('result_updated_at', 'nfl_games_result_updated_at_index');
        });

        // Start from the game's persisted result timestamp without reading the
        // target table through a correlated subquery. This remains safe on the
        // MySQL/MariaDB production path as well as SQLite test databases.
        DB::table('nfl_games')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->update([
                'result_updated_at' => DB::raw('updated_at'),
            ]);

        // Preserve completed outcome grades as fresh during rollout. Updating
        // one bounded group at a time avoids MySQL target-table update hazards
        // and prevents a one-time regrade of the historical observation set.
        DB::table('nfl_signal_grades as existing_result_grades')
            ->join(
                'nfl_signal_observations as existing_result_observations',
                'existing_result_observations.id',
                '=',
                'existing_result_grades.nfl_signal_observation_id',
            )
            ->where('existing_result_grades.evaluation_source', 'outcome')
            ->selectRaw(
                'existing_result_observations.game_id, MAX(existing_result_grades.updated_at) as graded_at',
            )
            ->groupBy('existing_result_observations.game_id')
            ->orderBy('existing_result_observations.game_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('nfl_games')
                        ->where('id', $row->game_id)
                        ->update(['result_updated_at' => $row->graded_at]);
                }
            });

        Schema::table('nfl_signal_observations', function (Blueprint $table): void {
            $table->index(
                ['season', 'id'],
                'nfl_signal_observations_pending_grade_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::table('nfl_signal_observations', function (Blueprint $table): void {
            $table->dropIndex('nfl_signal_observations_pending_grade_lookup');
        });

        Schema::table('nfl_games', function (Blueprint $table): void {
            $table->dropIndex('nfl_games_result_updated_at_index');
            $table->dropColumn('result_updated_at');
        });
    }
};
