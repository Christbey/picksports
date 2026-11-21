<?php

namespace App\Services\NBA\Injuries;

use App\Models\NBA\Team;
use Illuminate\Support\Facades\DB;

class InjuryAcquisitionOrder
{
    /** Reserve before network I/O; team locking serializes sequence allocation. */
    public function begin(int $teamId): int
    {
        return DB::transaction(function () use ($teamId): int {
            Team::query()->whereKey($teamId)->lockForUpdate()->firstOrFail();
            DB::table('nba_injury_team_states')->insertOrIgnore(['team_id' => $teamId, 'issued_sequence' => 0]);
            DB::table('nba_injury_team_states')->where('team_id', $teamId)->increment('issued_sequence');

            return (int) DB::table('nba_injury_team_states')->where('team_id', $teamId)->value('issued_sequence');
        });
    }
}
