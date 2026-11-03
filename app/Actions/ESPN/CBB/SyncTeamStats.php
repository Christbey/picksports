<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
use App\Services\CBB\CbbDataQuality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncTeamStats extends AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;

    protected const TEAM_TYPE_MODE = 'compare_game_team';

    public function execute(array $gameData, Model $game): int
    {
        if (empty($gameData['boxscore']['teams'])) {
            return 0;
        }
        if ($game->status !== 'STATUS_FINAL' && collect($gameData['boxscore']['teams'])->every(fn (array $team): bool => empty($team['statistics']))) {
            return 0;
        }

        return DB::transaction(function () use ($gameData, $game): int {
            $count = parent::execute($gameData, $game);
            if ($game instanceof Game && $game->status === 'STATUS_FINAL') {
                $issues = app(CbbDataQuality::class)->boxScoreIssues($game, $game->teamStats()->get());
                if ($issues !== []) {
                    throw new RuntimeException('Rejected incomplete CBB box score: '.implode(', ', $issues));
                }
            }

            return $count;
        });
    }

    protected function calculatePossessions(array $stats): ?float
    {
        $fga = $stats['fieldGoalsAttempted'] ?? 0;
        $oreb = $stats['offensiveRebounds'] ?? 0;
        $turnovers = $stats['turnovers'] ?? 0;
        $fta = $stats['freeThrowsAttempted'] ?? 0;

        return $fga - $oreb + $turnovers + ((float) config('cbb.metrics.possession_coefficient', 0.4) * $fta);
    }
}
