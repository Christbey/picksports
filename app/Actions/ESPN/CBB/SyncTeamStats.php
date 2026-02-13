<?php

namespace App\Actions\ESPN\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;

class SyncTeamStats
{
    public function execute(array $gameData, Game $game): int
    {
        if (! isset($gameData['boxscore']['teams'])) {
            return 0;
        }

        // Delete existing team stats for this game
        TeamStat::query()->where('game_id', $game->id)->delete();

        $synced = 0;

        foreach ($gameData['boxscore']['teams'] as $teamData) {
            $team = Team::query()->where('espn_id', $teamData['team']['id'])->first();

            if (! $team) {
                continue;
            }

            $stats = $this->parseTeamStats($teamData['statistics']);

            // Calculate points if not provided
            // Points = (FG - 3PT) * 2 + (3PT * 3) + FT
            $fgMade = $stats['fieldGoalsMade'] ?? 0;
            $threeMade = $stats['threePointFieldGoalsMade'] ?? 0;
            $ftMade = $stats['freeThrowsMade'] ?? 0;
            $calculatedPoints = (($fgMade - $threeMade) * 2) + ($threeMade * 3) + $ftMade;

            // Calculate possessions if not provided by ESPN
            // Formula: Possessions = FGA - OREB + TO + (0.4 * FTA)
            $fga = $stats['fieldGoalsAttempted'] ?? 0;
            $oreb = $stats['offensiveRebounds'] ?? 0;
            $to = $stats['turnovers'] ?? 0;
            $fta = $stats['freeThrowsAttempted'] ?? 0;
            $calculatedPossessions = $fga - $oreb + $to + (0.4 * $fta);

            TeamStat::create([
                'team_id' => $team->id,
                'game_id' => $game->id,
                'team_type' => $teamData['homeAway'] ?? null,
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
                'possessions' => $stats['possessions'] ?? $calculatedPossessions,
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

            // Handle made-attempted format (e.g., "39-81")
            if (str_contains($name, '-')) {
                $parts = explode('-', $name);
                $valueParts = explode('-', $value);

                if (count($valueParts) === 2) {
                    $parsed[$parts[0]] = (int) $valueParts[0];
                    $parsed[$parts[1]] = (int) $valueParts[1];
                }
            } else {
                // Handle single value stats
                $parsed[$name] = is_numeric($value) ? (str_contains($value, '.') ? (float) $value : (int) $value) : $value;
            }
        }

        return $parsed;
    }
}
