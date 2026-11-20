<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // @contract-migration Retire only the obsolete one-row-per-game/week unique indexes.
        // @requires-zero-legacy-usage Deploy the active_slot-aware CalculateElo/EloRebuildService
        // readers and writers before this step. No application code uses the retired index names.
        $indexes = collect(Schema::getIndexes('cfb_elo_ratings'));
        $activeKey = $indexes->first(fn (array $index): bool => $index['unique']
            && $index['columns'] === ['team_id', 'game_id', 'active_slot']);
        $teamKey = $indexes->first(fn (array $index): bool => $index['columns'] === ['team_id']);
        if ($activeKey === null || $teamKey === null) {
            throw new RuntimeException('Expand the active CFB Elo unique key and supporting team index before retiring legacy uniqueness.');
        }
        if (DB::table('cfb_elo_ratings')->whereNotNull('active_slot')->where('active_slot', '!=', 1)->exists()) {
            throw new RuntimeException('Unexpected CFB Elo active-slot values require review before retiring legacy uniqueness.');
        }

        foreach (['cfb_elo_ratings_team_id_season_week_season_type_unique', 'cfb_elo_ratings_team_id_game_id_unique'] as $index) {
            if (Schema::hasIndex('cfb_elo_ratings', $index)) {
                Schema::table('cfb_elo_ratings', fn (Blueprint $table) => $table->dropUnique($index));
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Cannot restore legacy CFB Elo uniqueness without discarding retained rating versions.');
    }
};
