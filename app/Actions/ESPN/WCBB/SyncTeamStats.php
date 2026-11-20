<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractBasketballSyncTeamStats;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamStat;
use App\Services\WCBB\WcbbMetricSourceEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncTeamStats extends AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_STAT_MODEL_CLASS = TeamStat::class;

    public function execute(array $gameData, Model $game): int
    {
        $teamRows = $gameData['boxscore']['teams'] ?? [];
        if (! is_array($teamRows) || $teamRows === []) {
            return 0;
        }

        $expectedIds = [(int) $game->home_team_id, (int) $game->away_team_id];
        $mappedIds = [];
        foreach ($teamRows as $teamData) {
            $team = Team::query()->where('espn_id', data_get($teamData, 'team.id'))->first();
            if ($team === null || ! in_array((int) $team->id, $expectedIds, true) || in_array((int) $team->id, $mappedIds, true)) {
                return 0;
            }
            $statistics = $teamData['statistics'] ?? [];
            if (! $this->requiredValuesAreNumeric($statistics)) {
                return 0;
            }
            $stats = $this->parseTeamStats($statistics);
            $points = $stats['points'] ?? null;
            if ($points === null) {
                foreach (['fieldGoalsMade', 'threePointFieldGoalsMade', 'freeThrowsMade'] as $field) {
                    if (! isset($stats[$field]) || ! is_numeric($stats[$field]) || (float) $stats[$field] < 0) {
                        return 0;
                    }
                }
                $points = 2 * $stats['fieldGoalsMade'] + $stats['threePointFieldGoalsMade'] + $stats['freeThrowsMade'];
            }
            $stat = new TeamStat([
                'points' => $points, 'possessions' => $stats['possessions'] ?? null,
                'field_goals_attempted' => $stats['fieldGoalsAttempted'] ?? null,
                'offensive_rebounds' => $stats['offensiveRebounds'] ?? null,
                'turnovers' => $stats['turnovers'] ?? null,
                'free_throws_attempted' => $stats['freeThrowsAttempted'] ?? null,
            ]);
            $finalScore = (int) $team->id === (int) $game->home_team_id ? $game->home_score : $game->away_score;
            if (! app(WcbbMetricSourceEvidence::class)->statIsUsable($stat)
                || ($game->status === 'STATUS_FINAL' && $finalScore !== null && (float) $points !== (float) $finalScore)) {
                Log::warning('WCBB box score held because scoring or possession source evidence is incomplete.', ['game_id' => $game->id]);

                return 0;
            }
            $mappedIds[] = (int) $team->id;
        }
        if (count($mappedIds) !== 2) {
            return 0;
        }

        return DB::transaction(fn (): int => parent::execute($gameData, $game));
    }

    private function requiredValuesAreNumeric(array $statistics): bool
    {
        $required = ['points', 'possessions', 'fieldGoalsAttempted', 'offensiveRebounds', 'turnovers',
            'freeThrowsAttempted', 'fieldGoalsMade', 'threePointFieldGoalsMade', 'freeThrowsMade'];
        foreach ($statistics as $stat) {
            $names = explode('-', (string) ($stat['name'] ?? ''));
            if (array_intersect($names, $required) === []) {
                continue;
            }
            $values = count($names) > 1 ? explode('-', (string) ($stat['displayValue'] ?? '')) : [$stat['displayValue'] ?? null];
            if (count($values) !== count($names)) {
                return false;
            }
            foreach ($values as $value) {
                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
