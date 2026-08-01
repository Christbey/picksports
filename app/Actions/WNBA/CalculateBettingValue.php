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

    protected function analyzeSpread(object $game, object $prediction, array $market): ?array
    {
        $recommendation = parent::analyzeSpread($game, $prediction, $market);

        if ($recommendation === null || ! (bool) config('wnba.betting.spread_gate.enabled', true)) {
            return $recommendation;
        }

        $edge = (float) ($recommendation['edge'] ?? 0.0);
        $homeLine = (float) ($recommendation['market_home_line'] ?? $recommendation['market_line'] ?? 0.0);
        $betHome = (string) ($recommendation['bet_team'] ?? '') === (string) ($recommendation['home_team'] ?? '');
        $isFavorite = $betHome ? $homeLine < 0 : $homeLine > 0;
        $winnerConfidence = (float) ($prediction->confidence_score ?? 0.0);
        $blockedFavoriteConfidence = (float) config('wnba.betting.spread_gate.block_favorite_confidence', 80.0);

        if ($isFavorite && $winnerConfidence >= $blockedFavoriteConfidence) {
            return null;
        }

        $validatedMin = (float) config('wnba.betting.spread_gate.validated_min_edge', 3.0);
        $validatedMax = (float) config('wnba.betting.spread_gate.validated_max_edge', 5.0);
        $underdogMin = (float) config('wnba.betting.spread_gate.underdog_min_edge', 2.5);
        $underdogMax = (float) config('wnba.betting.spread_gate.underdog_max_edge', 5.0);

        $inValidatedEdgeBucket = $edge >= $validatedMin && $edge < $validatedMax;
        $isUnderdogLean = ! $isFavorite && $edge >= $underdogMin && $edge < $underdogMax;

        if (! $inValidatedEdgeBucket && ! $isUnderdogLean) {
            return null;
        }

        return [
            ...$recommendation,
            'spread_gate' => [
                'applied' => true,
                'pick_type' => $isFavorite ? 'favorite' : 'underdog',
                'validated_edge_bucket' => $inValidatedEdgeBucket,
                'underdog_lean' => $isUnderdogLean,
            ],
            'reason_tags' => array_values(array_unique([
                ...((array) ($recommendation['reason_tags'] ?? [])),
                'wnba_spread_gate',
                $isFavorite ? 'favorite_spread' : 'underdog_spread',
            ])),
        ];
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
