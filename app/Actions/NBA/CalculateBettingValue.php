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
        $location = trim((string) ($team->location ?? ''));
        $name = trim((string) ($team->name ?? ''));
        $fullName = trim("{$location} {$name}");

        return $fullName !== ''
            ? $fullName
            : (string) ($team->abbreviation ?? 'Unknown');
    }

    protected function teamMatchesOutcome(object $team, string $outcomeName, string $oddsApiTeamName): bool
    {
        $haystack = $this->normalizeTeamName(
            trim("{$outcomeName} {$oddsApiTeamName}")
        );
        if ($haystack === '') {
            return false;
        }

        $candidates = array_filter([
            trim((string) ($team->location ?? '').' '.(string) ($team->name ?? '')),
            $team->location ?? null,
            $team->name ?? null,
            $team->abbreviation ?? null,
        ], fn (mixed $value): bool => trim((string) $value) !== '');

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeTeamName((string) $candidate);

            if ($normalized !== '' && ($haystack === $normalized || str_contains($haystack, $normalized))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTeamName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['.', '-', '_'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return str_replace(
            ['la clippers', 'la lakers', 'ny knicks'],
            ['los angeles clippers', 'los angeles lakers', 'new york knicks'],
            $normalized
        );
    }
}
