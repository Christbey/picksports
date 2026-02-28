<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractFootballSyncTeamStats;

class SyncTeamStats extends AbstractFootballSyncTeamStats
{
    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const TEAM_STAT_MODEL_CLASS = \App\Models\CFB\TeamStat::class;

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
                'thirdDownEff' => $this->parseFraction($value, 'thirdDownConversions', 'thirdDownAttempts', $parsed),
                'fourthDownEff' => $this->parseFraction($value, 'fourthDownConversions', 'fourthDownAttempts', $parsed),
                'totalPenaltiesYards' => $this->parseFraction($value, 'penalties', 'penaltyYards', $parsed),
                'sacksYardsLost' => $this->parseFraction($value, 'sacks', null, $parsed),
                default => null,
            };
        }

        return $parsed;
    }

    protected function sportSpecificAttributes(array $stats): array
    {
        return [
            'turnovers' => $stats['turnovers'] ?? null,
            'possession_time' => $stats['possessionTime'] ?? null,
            'sacks' => $stats['sacks'] ?? null,
        ];
    }
}
