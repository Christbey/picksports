<?php

namespace App\Services\CFB\Predictions;

use Carbon\CarbonImmutable;

class CfbSpreadAssessment
{
    /** A margin comparison is not a calibrated probability of covering. */
    public function assess(array $inputs, float $homeMargin, float $homeLine, array $riskFlags = []): array
    {
        $favorite = $homeLine < 0 ? 'home' : ($homeLine > 0 ? 'away' : null);
        $favoriteMargin = $favorite === null ? null : ($favorite === 'home' ? $homeMargin : -$homeMargin);
        $edge = $favoriteMargin === null ? null : round($favoriteMargin - abs($homeLine), 1);
        $flags = [...$riskFlags, ...CfbPredictionInputQuality::assess($inputs)['risk_flags']];
        foreach (['home', 'away'] as $side) {
            if ((int) data_get($inputs, $side.'.metrics.record_season') !== (int) data_get($inputs, 'event.season')) {
                $flags[] = $side.'_current_season_metrics_missing';
            }
            if (! is_numeric(data_get($inputs, $side.'.metrics.fpi'))) {
                $flags[] = $side.'_missing_opponent_adjusted_rating';
            }
            $evidence = (array) data_get($inputs, $side.'.rating_evidence', []);
            if (! isset($evidence['season'], $evidence['observed_at'])
                || (int) $evidence['season'] !== (int) data_get($inputs, 'event.season')) {
                $flags[] = $side.'_rating_provenance_unverified';
            } else {
                try {
                    $observed = CarbonImmutable::parse($evidence['observed_at']);
                    if ($observed->gt(now()) || $observed->lt(now()->subDays(14))) {
                        $flags[] = $side.'_stale_rating';
                    }
                } catch (\Throwable) {
                    $flags[] = $side.'_rating_provenance_unverified';
                }
            }
            if (! in_array(data_get($inputs, $side.'.availability.status'), ['last_observed', 'confirmed'], true)) {
                $flags[] = $side.'_quarterback_evidence_missing';
            }
        }
        $flags = array_values(array_unique($flags));
        $projection = $edge === null ? 'pick_em' : ($edge > 0 ? 'favorite_cover' : ($edge < 0 ? 'underdog_cover' : 'push'));
        $status = $flags !== [] ? 'insufficient_evidence' : $projection;
        $label = match ($status) {
            'insufficient_evidence' => 'Insufficient evidence for a cover recommendation',
            'favorite_cover' => 'Model projects the favorite to cover',
            'underdog_cover' => 'Model projects the underdog to cover',
            'push' => 'Model margin equals the spread',
            default => 'Market is pick’em',
        };

        return [
            'status' => $status, 'label' => $label, 'projected_cover' => $projection,
            'favorite_side' => $favorite, 'market_home_line' => $homeLine,
            'model_home_margin' => round($homeMargin, 1),
            'favorite_projected_margin' => $favoriteMargin === null ? null : round($favoriteMargin, 1),
            'favorite_cover_edge_points' => $edge,
            'large_favorite_agreement' => $favoriteMargin !== null && abs($homeLine) >= 21 && $favoriteMargin >= 21,
            'cover_probability' => null, 'probability_status' => 'not_calibrated',
            'risk_flags' => $flags,
            'summary' => $favorite === null ? $label : sprintf(
                '%s. Favorite: market by %.1f; model by %.1f; cover edge %+.1f points.',
                $label, abs($homeLine), $favoriteMargin, $edge,
            ),
        ];
    }
}
