<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractBasketballSyncPlayerStats;
use App\Models\CBB\Player;
use App\Models\CBB\PlayerStat;
use App\Models\CBB\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncPlayerStats extends AbstractBasketballSyncPlayerStats
{
    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const PLAYER_STAT_MODEL_CLASS = PlayerStat::class;

    protected const SKIP_DNP_OR_EMPTY_STATS = true;

    public function execute(array $gameData, Model $game): int
    {
        if (empty($gameData['boxscore']['players'])) {
            return 0;
        }
        foreach ($gameData['boxscore']['players'] as &$team) {
            $block = $team['statistics'][0] ?? [];
            $labels = $block['labels'] ?? $block['names'] ?? [];
            if ($labels === []) {
                throw new RuntimeException('CBB player stat column labels are unavailable.');
            }
            $aliases = [
                ['MIN', 'minutes'], ['PTS', 'points'], ['FG', 'fieldGoalsMade-fieldGoalsAttempted'],
                ['3PT', '3P', 'threePointFieldGoalsMade-threePointFieldGoalsAttempted'],
                ['FT', 'freeThrowsMade-freeThrowsAttempted'], ['REB', 'totalRebounds'], ['AST', 'assists'],
                ['TO', 'turnovers'], ['STL', 'steals'], ['BLK', 'blocks'], ['OREB', 'offensiveRebounds'],
                ['DREB', 'defensiveRebounds'], ['PF', 'fouls'],
            ];
            $normalizedLabels = array_map(fn ($label): string => strtolower((string) $label), $labels);
            foreach ($aliases as $names) {
                if (array_intersect(array_map('strtolower', $names), $normalizedLabels) === []) {
                    throw new RuntimeException('CBB player stat column is unavailable: '.$names[0]);
                }
            }
            foreach ($team['statistics'][0]['athletes'] as &$athlete) {
                if (($athlete['didNotPlay'] ?? false) || empty($athlete['stats'])) {
                    continue;
                }
                $values = $athlete['stats'];
                $athlete['stats'] = array_map(function (array $names) use ($values, $normalizedLabels): mixed {
                    foreach ($names as $name) {
                        $index = array_search(strtolower($name), $normalizedLabels, true);
                        if ($index !== false) {
                            return $values[$index] ?? null;
                        }
                    }

                    return null;
                }, $aliases);
            }
            unset($athlete);
        }
        unset($team);

        return DB::transaction(function () use ($gameData, $game): int {
            $count = parent::execute($gameData, $game);
            if ($game->status === 'STATUS_FINAL') {
                $totals = $game->playerStats()->selectRaw('team_id, sum(points) as points')->groupBy('team_id')->get()->keyBy('team_id');
                foreach ([$game->home_team_id => $game->home_score, $game->away_team_id => $game->away_score] as $teamId => $score) {
                    if ($score === null || ! $totals->has($teamId) || (int) $totals[$teamId]->points !== (int) $score) {
                        throw new RuntimeException('Rejected incomplete CBB player box score: points do not match final.');
                    }
                }
            }

            return $count;
        });
    }
}
