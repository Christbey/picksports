<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
use App\Services\CBB\CbbDataQuality;
use App\Services\CBB\TeamScoringCredit;
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

        if ($game instanceof Game && $game->status === 'STATUS_FINAL') {
            foreach ($gameData['boxscore']['teams'] as &$section) {
                $team = Team::where('espn_id', data_get($section, 'team.id'))->first();
                if ($team === null) {
                    continue;
                }
                $stats = $this->parseTeamStats($section['statistics'] ?? []);
                $shootingPoints = 2 * ($stats['fieldGoalsMade'] ?? 0) + ($stats['threePointFieldGoalsMade'] ?? 0) + ($stats['freeThrowsMade'] ?? 0);
                $final = $team->id === $game->home_team_id ? $game->home_score : $game->away_score;
                $credit = $shootingPoints !== $final ? app(TeamScoringCredit::class)->points($game, (int) $team->id) : 0;
                if ($credit > 0 && ! array_key_exists('points', $stats) && $shootingPoints + $credit === $final) {
                    $section['statistics'][] = ['name' => 'points', 'displayValue' => (string) $final];
                }
            }
            unset($section);
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
        foreach (['fieldGoalsAttempted', 'offensiveRebounds', 'turnovers', 'freeThrowsAttempted'] as $field) {
            if (! isset($stats[$field]) || ! is_numeric($stats[$field])) {
                return null;
            }
        }
        $fga = $stats['fieldGoalsAttempted'];
        $oreb = $stats['offensiveRebounds'];
        $turnovers = $stats['turnovers'];
        $fta = $stats['freeThrowsAttempted'];

        return $fga - $oreb + $turnovers + ((float) config('cbb.metrics.possession_coefficient', 0.4) * $fta);
    }
}
