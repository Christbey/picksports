<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractLineBasedCalculateBettingValue;

class CalculateBettingValue extends AbstractLineBasedCalculateBettingValue
{
    protected function sportKey(): string
    {
        return 'nfl';
    }

    protected function getTeamDisplayName(object $team): string
    {
        $location = $team->location ?? '';
        $name = $team->name ?? '';

        return trim("{$location} {$name}") ?: $team->abbreviation ?? 'Unknown';
    }

    protected function teamMatchesOutcome(object $team, string $outcomeName, string $oddsApiTeamName): bool
    {
        $outcomeLower = strtolower($outcomeName);
        $oddsApiLower = strtolower($oddsApiTeamName);

        $outcomeLower = str_replace('los angeles', 'la', $outcomeLower);
        $oddsApiLower = str_replace('los angeles', 'la', $oddsApiLower);

        $teamLocation = strtolower($team->location ?? '');
        if (! empty($teamLocation) && (str_contains($outcomeLower, $teamLocation) || str_contains($oddsApiLower, $teamLocation))) {
            return true;
        }

        $teamName = strtolower($team->name ?? '');
        if (! empty($teamName) && str_contains($outcomeLower, $teamName)) {
            return true;
        }

        return $outcomeLower === $oddsApiLower;
    }
}
