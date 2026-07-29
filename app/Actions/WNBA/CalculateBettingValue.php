<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractLineBasedCalculateBettingValue;

class CalculateBettingValue extends AbstractLineBasedCalculateBettingValue
{
    protected function sportKey(): string
    {
        return 'wnba';
    }

    protected function getTeamDisplayName(object $team): string
    {
        $displayName = trim((string) ($team->display_name ?? ''));
        if ($displayName !== '') {
            return $displayName;
        }

        $location = trim((string) ($team->location ?? $team->school ?? ''));
        $name = trim((string) ($team->name ?? $team->mascot ?? ''));
        $fullName = trim("{$location} {$name}");

        return $fullName !== '' ? $fullName : (string) ($team->abbreviation ?? 'Unknown');
    }

    protected function teamMatchesOutcome(object $team, string $outcomeName, string $oddsApiTeamName): bool
    {
        $haystack = $this->normalizeTeamName(trim("{$outcomeName} {$oddsApiTeamName}"));
        if ($haystack === '') {
            return false;
        }

        $candidates = array_filter([
            $team->display_name ?? null,
            trim((string) ($team->location ?? $team->school ?? '').' '.(string) ($team->name ?? $team->mascot ?? '')),
            $team->location ?? $team->school ?? null,
            $team->name ?? $team->mascot ?? null,
            $team->short_display_name ?? null,
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

        return str_replace([
            'la sparks',
            'ny liberty',
            'lv aces',
        ], [
            'los angeles sparks',
            'new york liberty',
            'las vegas aces',
        ], $normalized);
    }
}
