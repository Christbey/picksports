<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;

class SyncTeamStats extends AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;

    protected const TEAM_TYPE_MODE = 'home_away';

    protected function calculatePossessions(array $stats): ?float
    {
        $fga = $stats['fieldGoalsAttempted'] ?? 0;
        $oreb = $stats['offensiveRebounds'] ?? 0;
        $turnovers = $stats['turnovers'] ?? 0;
        $fta = $stats['freeThrowsAttempted'] ?? 0;

        return $fga - $oreb + $turnovers + (0.4 * $fta);
    }
}
