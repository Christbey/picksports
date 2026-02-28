<?php

namespace App\Actions\ESPN;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractBasketballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = Model::class;

    protected const TEAM_STAT_MODEL_CLASS = Model::class;

    protected const TEAM_TYPE_MODE = 'compare_game_team';

    public function execute(array $gameData, Model $game): int
    {
        if (! isset($gameData['boxscore']['teams'])) {
            return 0;
        }

        $teamStatModel = $this->teamStatModelClass();
        $teamStatModel::query()->where('game_id', $game->id)->delete();

        $synced = 0;
        $teamModel = $this->teamModelClass();

        foreach ($gameData['boxscore']['teams'] as $teamData) {
            $team = $teamModel::query()->where('espn_id', $teamData['team']['id'])->first();

            if (! $team) {
                continue;
            }

            $stats = $this->parseTeamStats($teamData['statistics'] ?? []);

            $fgMade = $stats['fieldGoalsMade'] ?? 0;
            $threeMade = $stats['threePointFieldGoalsMade'] ?? 0;
            $ftMade = $stats['freeThrowsMade'] ?? 0;
            $calculatedPoints = (($fgMade - $threeMade) * 2) + ($threeMade * 3) + $ftMade;

            $teamStatModel::create([
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
                'points' => $stats['points'] ?? $calculatedPoints,
                'possessions' => $stats['possessions'] ?? $this->calculatePossessions($stats),
                'fast_break_points' => $stats['fastBreakPoints'] ?? null,
                'points_in_paint' => $stats['pointsInPaint'] ?? null,
                'second_chance_points' => $stats['secondChancePoints'] ?? null,
                'bench_points' => $stats['benchPoints'] ?? null,
                'biggest_lead' => $stats['biggestLead'] ?? null,
                'times_tied' => $stats['timesTied'] ?? null,
                'lead_changes' => $stats['leadChanges'] ?? null,
            ]);

            $synced++;
        }

        return $synced;
    }

    protected function parseTeamStats(array $statistics): array
    {
        $parsed = [];

        foreach ($statistics as $stat) {
            $name = $stat['name'];
            $value = $stat['displayValue'];

            if (str_contains($name, '-')) {
                $parts = explode('-', $name);
                $valueParts = explode('-', $value);

                if (count($valueParts) === 2) {
                    $parsed[$parts[0]] = (int) $valueParts[0];
                    $parsed[$parts[1]] = (int) $valueParts[1];
                }
            } else {
                $parsed[$name] = is_numeric($value)
                    ? (str_contains($value, '.') ? (float) $value : (int) $value)
                    : $value;
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
