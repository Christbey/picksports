<?php

namespace App\Actions\ESPN\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;

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

            // Determine home/away from game record
            $teamType = ($team->id === $game->home_team_id) ? 'home' : 'away';

            $stats = $this->parseTeamStats($teamData['statistics']);

            TeamStat::create([
                'team_id' => $team->id,
                'game_id' => $game->id,
                'team_type' => $teamType,
                'total_yards' => $stats['totalYards'] ?? null,
                'passing_yards' => $stats['passingYards'] ?? null,
                'passing_completions' => $stats['completions'] ?? null,
                'passing_attempts' => $stats['passingAttempts'] ?? null,
                'passing_touchdowns' => $stats['passingTouchdowns'] ?? null,
                'interceptions' => $stats['interceptions'] ?? null,
                'rushing_yards' => $stats['rushingYards'] ?? null,
                'rushing_attempts' => $stats['rushingAttempts'] ?? null,
                'rushing_touchdowns' => $stats['rushingTouchdowns'] ?? null,
                'fumbles' => $stats['fumbles'] ?? null,
                'fumbles_lost' => $stats['fumblesLost'] ?? null,
                'sacks_allowed' => $stats['sacksAllowed'] ?? null,
                'first_downs' => $stats['firstDowns'] ?? null,
                'third_down_conversions' => $stats['thirdDownConversions'] ?? null,
                'third_down_attempts' => $stats['thirdDownAttempts'] ?? null,
                'fourth_down_conversions' => $stats['fourthDownConversions'] ?? null,
                'fourth_down_attempts' => $stats['fourthDownAttempts'] ?? null,
                'red_zone_attempts' => $stats['redZoneAttempts'] ?? null,
                'red_zone_scores' => $stats['redZoneScores'] ?? null,
                'penalties' => $stats['penalties'] ?? null,
                'penalty_yards' => $stats['penaltyYards'] ?? null,
                'time_of_possession' => $stats['possessionTime'] ?? null,
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

            match ($name) {
                'firstDowns' => $parsed['firstDowns'] = (int) $value,
                'totalYards' => $parsed['totalYards'] = (int) $value,
                'netPassingYards' => $parsed['passingYards'] = (int) $value,
                'rushingYards' => $parsed['rushingYards'] = (int) $value,
                'rushingAttempts' => $parsed['rushingAttempts'] = (int) $value,
                'interceptions' => $parsed['interceptions'] = (int) $value,
                'fumblesLost' => $parsed['fumblesLost'] = (int) $value,
                'possessionTime' => $parsed['possessionTime'] = $value,

                // Handle slash-separated values (completions/attempts)
                'completionAttempts' => $this->parseFraction($value, 'completions', 'passingAttempts', $parsed),

                // Handle dash-separated values (made-attempted or count-yards)
                'thirdDownEff' => $this->parseFraction($value, 'thirdDownConversions', 'thirdDownAttempts', $parsed),
                'fourthDownEff' => $this->parseFraction($value, 'fourthDownConversions', 'fourthDownAttempts', $parsed),
                'redZoneAttempts' => $this->parseFraction($value, 'redZoneScores', 'redZoneAttempts', $parsed),
                'totalPenaltiesYards' => $this->parseFraction($value, 'penalties', 'penaltyYards', $parsed),
                'sacksYardsLost' => $this->parseFraction($value, 'sacksAllowed', null, $parsed), // Only need first value

                default => null,
            };
        }

        return $parsed;
    }

    protected function parseFraction(string $value, string $firstKey, ?string $secondKey, array &$parsed): void
    {
        // Handle both "/" and "-" separators
        $separator = str_contains($value, '/') ? '/' : '-';
        $parts = explode($separator, $value);

        if (count($parts) === 2) {
            $parsed[$firstKey] = (int) $parts[0];
            if ($secondKey) {
                $parsed[$secondKey] = (int) $parts[1];
            }
        }
    }
}
