<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractFootballSyncTeamStats;
use Illuminate\Database\Eloquent\Model;

class SyncTeamStats extends AbstractFootballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\NFL\Team::class;

    protected const TEAM_STAT_MODEL_CLASS = \App\Models\NFL\TeamStat::class;

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
                'completionAttempts' => $this->parseFraction($value, 'completions', 'passingAttempts', $parsed),
                'thirdDownEff' => $this->parseFraction($value, 'thirdDownConversions', 'thirdDownAttempts', $parsed),
                'fourthDownEff' => $this->parseFraction($value, 'fourthDownConversions', 'fourthDownAttempts', $parsed),
                'redZoneAttempts' => $this->parseFraction($value, 'redZoneScores', 'redZoneAttempts', $parsed),
                'totalPenaltiesYards' => $this->parseFraction($value, 'penalties', 'penaltyYards', $parsed),
                'sacksYardsLost' => $this->parseFraction($value, 'sacksAllowed', null, $parsed),
                default => null,
            };
        }

        return $parsed;
    }

    protected function sportSpecificAttributes(array $stats): array
    {
        return [
            'passing_completions' => $stats['completions'] ?? null,
            'passing_attempts' => $stats['passingAttempts'] ?? null,
            'passing_touchdowns' => $stats['passingTouchdowns'] ?? null,
            'interceptions' => $stats['interceptions'] ?? null,
            'rushing_attempts' => $stats['rushingAttempts'] ?? null,
            'rushing_touchdowns' => $stats['rushingTouchdowns'] ?? null,
            'fumbles' => $stats['fumbles'] ?? null,
            'fumbles_lost' => $stats['fumblesLost'] ?? null,
            'sacks_allowed' => $stats['sacksAllowed'] ?? null,
            'red_zone_attempts' => $stats['redZoneAttempts'] ?? null,
            'red_zone_scores' => $stats['redZoneScores'] ?? null,
            'time_of_possession' => $stats['possessionTime'] ?? null,
        ];
    }

    protected function resolveTeamType(Model $team, Model $game, array $teamData): ?string
    {
        return ($team->id === $game->home_team_id) ? 'home' : 'away';
    }
}
