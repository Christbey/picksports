<?php

namespace App\Actions\ESPN;

use App\Actions\ESPN\Concerns\ValidatesBoxscoreReplacement;
use App\Support\ESPN\BasketballStatValue;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractBasketballSyncTeamStats
{
    use ValidatesBoxscoreReplacement;

    protected const TEAM_MODEL_CLASS = Model::class;

    protected const TEAM_STAT_MODEL_CLASS = Model::class;

    protected const TEAM_TYPE_MODE = 'compare_game_team';

    public function execute(array $gameData, Model $game): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game): int {
            $game = $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();

            return $this->replaceTeamStats($gameData, $game);
        });
    }

    protected function replaceTeamStats(array $gameData, Model $game): int
    {
        $sections = $gameData['boxscore']['teams'] ?? null;
        if (! is_array($sections) || $sections === []) {
            return 0;
        }

        $teamStatModel = $this->teamStatModelClass();

        $synced = 0;
        $teamModel = $this->teamModelClass();
        $teams = $this->replacementTeams($sections, $game, $teamModel, $teamStatModel);
        if ($teams === null) {
            return 0;
        }
        $rows = [];

        foreach ($sections as $teamData) {
            $team = $teams->get($teamData['team']['id']);
            if (! is_array($teamData['statistics'] ?? null) || $teamData['statistics'] === []) {
                return 0;
            }

            $stats = $this->parseTeamStats($teamData['statistics'] ?? []);

            if ($stats === null) {
                return 0;
            }
            $shooting = ['fieldGoalsMade', 'fieldGoalsAttempted', 'threePointFieldGoalsMade', 'threePointFieldGoalsAttempted', 'freeThrowsMade', 'freeThrowsAttempted'];
            foreach (array_chunk($shooting, 2) as [$made, $attempted]) {
                if (isset($stats[$made], $stats[$attempted]) && $stats[$made] > $stats[$attempted]) {
                    return 0;
                }
            }
            if (isset($stats['threePointFieldGoalsMade'], $stats['fieldGoalsMade'])
                && $stats['threePointFieldGoalsMade'] > $stats['fieldGoalsMade']) {
                return 0;
            }
            $calculatedPoints = count(array_intersect($shooting, array_keys($stats))) === count($shooting)
                ? (($stats['fieldGoalsMade'] - $stats['threePointFieldGoalsMade']) * 2)
                    + ($stats['threePointFieldGoalsMade'] * 3) + $stats['freeThrowsMade']
                : null;
            $points = $stats['points'] ?? $calculatedPoints;
            if ($points === null && $teamStatModel::query()->where('game_id', $game->id)->where('team_id', $team->id)->whereNotNull('points')->exists()) {
                return 0;
            }

            $attributes = [
                'team_id' => $team->id,
                'game_id' => $game->id,
                'team_type' => $this->resolveTeamType($teamData, $game, $team),
                'field_goals_made' => $stats['fieldGoalsMade'] ?? null,
                'field_goals_attempted' => $stats['fieldGoalsAttempted'] ?? null,
                'three_point_made' => $stats['threePointFieldGoalsMade'] ?? null,
                'three_point_attempted' => $stats['threePointFieldGoalsAttempted'] ?? null,
                'free_throws_made' => $stats['freeThrowsMade'] ?? null,
                'free_throws_attempted' => $stats['freeThrowsAttempted'] ?? null,
                'rebounds' => $stats['totalRebounds'] ?? null,
                'offensive_rebounds' => $stats['offensiveRebounds'] ?? null,
                'defensive_rebounds' => $stats['defensiveRebounds'] ?? null,
                'assists' => $stats['assists'] ?? null,
                'turnovers' => $stats['turnovers'] ?? null,
                'steals' => $stats['steals'] ?? null,
                'blocks' => $stats['blocks'] ?? null,
                'fouls' => $stats['fouls'] ?? null,
                'points' => $points,
                'possessions' => $stats['possessions'] ?? $this->calculatePossessions($stats),
                'fast_break_points' => $stats['fastBreakPoints'] ?? null,
                'points_in_paint' => $stats['pointsInPaint'] ?? null,
                'second_chance_points' => $stats['secondChancePoints'] ?? null,
                'bench_points' => $stats['benchPoints'] ?? null,
                'biggest_lead' => $stats['biggestLead'] ?? null,
                'times_tied' => $stats['timesTied'] ?? null,
                'lead_changes' => $stats['leadChanges'] ?? null,
            ];

            $observed = array_diff_key($attributes, array_flip(['team_id', 'game_id', 'team_type', 'points', 'possessions']));
            if (collect($observed)->every(fn ($value): bool => $value === null)
                && ! is_numeric($stats['points'] ?? null) && ! is_numeric($stats['possessions'] ?? null)) {
                return 0;
            }
            $rows[] = $attributes;

            $synced++;
        }

        $teamStatModel::query()->where('game_id', $game->id)->delete();
        foreach ($rows as $row) {
            $teamStatModel::query()->create($row);
        }

        return $synced;
    }

    protected function parseTeamStats(array $statistics): ?array
    {
        $parsed = [];
        $numeric = ['fieldGoalsMade', 'fieldGoalsAttempted', 'threePointFieldGoalsMade', 'threePointFieldGoalsAttempted',
            'freeThrowsMade', 'freeThrowsAttempted', 'totalRebounds', 'offensiveRebounds', 'defensiveRebounds',
            'assists', 'turnovers', 'steals', 'blocks', 'fouls', 'points', 'fastBreakPoints', 'pointsInPaint',
            'secondChancePoints', 'benchPoints', 'biggestLead', 'timesTied', 'leadChanges'];
        foreach ($statistics as $stat) {
            if (! is_array($stat) || ! is_string($stat['name'] ?? null) || ! array_key_exists('displayValue', $stat)) {
                return null;
            }
            $name = $stat['name'];
            $value = $stat['displayValue'];
            if (in_array($name, ['fieldGoalsMade-fieldGoalsAttempted',
                'threePointFieldGoalsMade-threePointFieldGoalsAttempted', 'freeThrowsMade-freeThrowsAttempted'], true)) {
                $parts = explode('-', $name);
                $pair = BasketballStatValue::madeAttempt($value);
                if ($pair === null) {
                    return null;
                }
                [$parsed[$parts[0]], $parsed[$parts[1]]] = $pair;
            } elseif (in_array($name, $numeric, true)) {
                $integer = BasketballStatValue::integer($value);
                if ($integer === null) {
                    return null;
                }
                $parsed[$name] = $integer;
            } elseif ($name === 'possessions') {
                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0) {
                    return null;
                }
                $parsed[$name] = (float) $value;
            }
        }

        return $parsed;
    }

    protected function calculatePossessions(array $stats): ?float
    {
        return null;
    }

    protected function resolveTeamType(array $teamData, Model $game, Model $team): ?string
    {
        return match (static::TEAM_TYPE_MODE) {
            'home_away' => $teamData['homeAway'] ?? null,
            default => ($team->id === $game->home_team_id) ? 'home' : 'away',
        };
    }

    /**
     * @return class-string<Model>
     */
    protected function teamModelClass(): string
    {
        if (static::TEAM_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('TEAM_MODEL_CLASS must be defined.');
        }

        return static::TEAM_MODEL_CLASS;
    }

    /**
     * @return class-string<Model>
     */
    protected function teamStatModelClass(): string
    {
        if (static::TEAM_STAT_MODEL_CLASS === Model::class) {
            throw new \RuntimeException('TEAM_STAT_MODEL_CLASS must be defined.');
        }

        return static::TEAM_STAT_MODEL_CLASS;
    }
}
