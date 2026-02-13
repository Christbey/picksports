<?php

namespace App\Actions\ESPN\CFB;

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;

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

            TeamStat::create([
                'team_id' => $team->id,
                'game_id' => $game->id,
                'total_yards' => $stats['totalYards'] ?? null,
                'passing_yards' => $stats['passingYards'] ?? null,
                'rushing_yards' => $stats['rushingYards'] ?? null,
                'first_downs' => $stats['firstDowns'] ?? null,
                'third_down_conversions' => $stats['thirdDownConversions'] ?? null,
                'third_down_attempts' => $stats['thirdDownAttempts'] ?? null,
                'fourth_down_conversions' => $stats['fourthDownConversions'] ?? null,
                'fourth_down_attempts' => $stats['fourthDownAttempts'] ?? null,
                'turnovers' => $stats['turnovers'] ?? null,
                'penalties' => $stats['penalties'] ?? null,
                'penalty_yards' => $stats['penaltyYards'] ?? null,
                'possession_time' => $stats['possessionTime'] ?? null,
                'sacks' => $stats['sacks'] ?? null,
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
                'turnovers' => $parsed['turnovers'] = (int) $value,
                'possessionTime' => $parsed['possessionTime'] = $value,

                // Handle slash-separated values
                'thirdDownEff' => $this->parseFraction($value, 'thirdDownConversions', 'thirdDownAttempts', $parsed),
                'fourthDownEff' => $this->parseFraction($value, 'fourthDownConversions', 'fourthDownAttempts', $parsed),
                'totalPenaltiesYards' => $this->parseFraction($value, 'penalties', 'penaltyYards', $parsed),
                'sacksYardsLost' => $this->parseFraction($value, 'sacks', null, $parsed), // Only need first value

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
