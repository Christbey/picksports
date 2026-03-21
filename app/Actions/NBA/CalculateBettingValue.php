<?php

namespace App\Actions\NBA;

use App\Actions\Sports\AbstractLineBasedCalculateBettingValue;

class CalculateBettingValue extends AbstractLineBasedCalculateBettingValue
{
    protected function sportKey(): string
    {
        return 'nba';
    }

    protected function getTeamDisplayName(object $team): string
    {
        $city = $team->school ?? '';
        $mascot = $team->mascot ?? '';

        return trim("{$city} {$mascot}") ?: $team->abbreviation ?? 'Unknown';
    }

    protected function teamMatchesOutcome(object $team, string $outcomeName, string $oddsApiTeamName): bool
    {
        $outcomeLower = strtolower($outcomeName);
        $oddsApiLower = strtolower($oddsApiTeamName);

        $outcomeLower = str_replace('los angeles', 'la', $outcomeLower);
        $oddsApiLower = str_replace('los angeles', 'la', $oddsApiLower);

        $teamCity = strtolower($team->school ?? '');
        if (! empty($teamCity) && (str_contains($outcomeLower, $teamCity) || str_contains($oddsApiLower, $teamCity))) {
            return true;
        }

        $mascot = strtolower($team->mascot ?? '');
        if (! empty($mascot) && str_contains($outcomeLower, $mascot)) {
            return true;
        }

        return $outcomeLower === $oddsApiLower;
    }
}
