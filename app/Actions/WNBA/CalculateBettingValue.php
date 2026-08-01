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
        $signalContext = (array) data_get($prediction, 'model_metadata.signal_context', []);
        $selectedKey = $betHome ? 'home' : 'away';
        $opponentKey = $betHome ? 'away' : 'home';
        $selectedSignals = (array) data_get($signalContext, $selectedKey, []);
        $opponentSignals = (array) data_get($signalContext, $opponentKey, []);
        $selectedRestDays = data_get($selectedSignals, 'rest_days');
        $selectedLast10AtsPct = data_get($selectedSignals, 'ats.last10.pct');
        $selectedLast5NetRating = data_get($selectedSignals, 'rolling_four_factors.last5.net_rating');
        $opponentLast5NetRating = data_get($opponentSignals, 'rolling_four_factors.last5.net_rating');
        $selectedNetRatingEdge = is_numeric($selectedLast5NetRating) && is_numeric($opponentLast5NetRating)
            ? round((float) $selectedLast5NetRating - (float) $opponentLast5NetRating, 2)
            : null;

        $inValidatedEdgeBucket = $edge >= $validatedMin && $edge < $validatedMax;
        $isUnderdogLean = ! $isFavorite && $edge >= $underdogMin && $edge < $underdogMax;

        if (! $inValidatedEdgeBucket && ! $isUnderdogLean) {
            return null;
        }

        if (is_numeric($selectedRestDays)
            && (float) $selectedRestDays <= 1.0
            && $edge < (float) config('wnba.betting.spread_gate.fatigue_min_edge', 4.0)) {
            return null;
        }

        if (is_numeric($selectedLast10AtsPct)
            && (float) $selectedLast10AtsPct < (float) config('wnba.betting.spread_gate.cold_ats_pct', 45.0)
            && $edge < (float) config('wnba.betting.spread_gate.cold_ats_min_edge', 4.0)) {
            return null;
        }

        if ($selectedNetRatingEdge !== null
            && $selectedNetRatingEdge < (float) config('wnba.betting.spread_gate.negative_net_rating_threshold', -6.0)
            && $edge < (float) config('wnba.betting.spread_gate.negative_four_factor_min_edge', 4.5)) {
            return null;
        }

        return [
            ...$recommendation,
            'spread_gate' => [
                'applied' => true,
                'pick_type' => $isFavorite ? 'favorite' : 'underdog',
                'validated_edge_bucket' => $inValidatedEdgeBucket,
                'underdog_lean' => $isUnderdogLean,
                'selected_rest_days' => is_numeric($selectedRestDays) ? (float) $selectedRestDays : null,
                'selected_last10_ats_pct' => is_numeric($selectedLast10AtsPct) ? (float) $selectedLast10AtsPct : null,
                'selected_last5_net_rating_edge' => $selectedNetRatingEdge,
            ],
            'reason_tags' => array_values(array_unique(array_filter([
                ...((array) ($recommendation['reason_tags'] ?? [])),
                'wnba_spread_gate',
                $isFavorite ? 'favorite_spread' : 'underdog_spread',
                is_numeric($selectedRestDays) && (float) $selectedRestDays <= 1.0 ? 'fatigue_watch' : null,
                is_numeric($selectedLast10AtsPct) && (float) $selectedLast10AtsPct >= 55.0 ? 'strong_recent_ats' : null,
                $selectedNetRatingEdge !== null && $selectedNetRatingEdge > 4.0 ? 'positive_four_factor_form' : null,
            ]))),
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
