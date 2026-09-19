<?php

namespace App\Services\CFB\Predictions;

use Carbon\CarbonImmutable;

class CfbEarlySeasonSpreadSupport
{
    /** Validate independent evidence without inflating the current-season sample. */
    public function assess(array $inputs, array $diagnostics, ?CarbonImmutable $capturedAt, string $safetyStatus): array
    {
        $flags = [];
        $priorGames = [];
        $minimumCurrent = (int) config('cfb.predictions.spread_value.minimum_sample_games', 6);
        $minimumPrior = (int) config('cfb.predictions.spread_value.early_season.minimum_prior_games', 8);
        $maximumRatingAge = (int) config('cfb.predictions.spread_value.early_season.maximum_rating_age_days', 14);
        if (! config('cfb.predictions.spread_value.early_season.enabled', true)) {
            $flags[] = 'early_season_support_disabled';
        }
        if ($safetyStatus !== 'verified' || ! $capturedAt) {
            $flags[] = 'unverified_pregame_snapshot';
        }
        if (($diagnostics['spread_baseline'] ?? null) !== 'fpi_points') {
            $flags[] = 'point_scale_rating_baseline_required';
        }
        $season = (int) data_get($inputs, 'event.season');
        $week = data_get($inputs, 'event.week');
        if (! is_numeric($week) || (int) $week < 1 || (int) $week > 6) {
            $flags[] = 'outside_early_season_window';
        }
        $samples = [];
        foreach (['home', 'away'] as $side) {
            if (data_get($inputs, 'require_personnel_evidence', false)
                && ! data_get($inputs, $side.'.personnel.coverage_complete', false)) {
                $missing = (array) data_get($inputs, $side.'.personnel.missing_components', []);
                foreach ($missing ?: ['coverage'] as $component) {
                    $flags[] = $side.'_personnel_'.$component.'_unverified';
                }
            }
            $metrics = (array) data_get($inputs, $side.'.metrics', []);
            $samples[$side] = (int) ($metrics['wins'] ?? 0) + (int) ($metrics['losses'] ?? 0);
            if ($season < 2000 || (int) ($metrics['record_season'] ?? 0) !== $season || $samples[$side] < 1) {
                $flags[] = $side.'_current_season_sample_required';
            }
            $prior = (array) data_get($inputs, $side.'.prior_metrics', []);
            $priorGames[$side] = (int) ($prior['wins'] ?? 0) + (int) ($prior['losses'] ?? 0);
            if ((int) ($prior['record_season'] ?? 0) !== $season - 1 || $priorGames[$side] < $minimumPrior
                || ! is_numeric($prior['points_per_game'] ?? null) || ! is_numeric($prior['points_allowed_per_game'] ?? null)) {
                $flags[] = $side.'_insufficient_previous_season_evidence';
            }
            $priorEvidence = (array) data_get($inputs, $side.'.prior_metric_evidence', []);
            if (! ($priorEvidence['metric_id'] ?? null)
                || ! $this->observedBefore($priorEvidence['observed_at'] ?? null, $capturedAt)) {
                $flags[] = $side.'_prior_provenance_unverified';
            }
            $rating = (array) data_get($inputs, $side.'.rating_evidence', []);
            if (! is_numeric($metrics['fpi'] ?? null) || ($rating['source'] ?? null) !== 'cfbd_fpi'
                || ($rating['units'] ?? null) !== 'points_above_average' || ! ($rating['rating_id'] ?? null)
                || (int) ($rating['season'] ?? 0) !== $season
                || ! $this->observedBefore($rating['observed_at'] ?? null, $capturedAt, $maximumRatingAge)) {
                $flags[] = $side.'_current_rating_evidence_required';
            }
            if (! in_array(data_get($inputs, $side.'.availability.status'), ['last_observed', 'confirmed'], true)
                || data_get($inputs, $side.'.availability.quarterback_hold', false)) {
                $flags[] = $side.'_quarterback_evidence_unresolved';
            }
        }
        if (min($samples) >= $minimumCurrent) {
            $flags[] = 'early_season_exception_not_needed';
        }
        $flags = array_values(array_unique([...$flags, ...CfbPredictionInputQuality::assess($inputs)['risk_flags']]));

        return ['eligible' => $flags === [], 'path' => 'early_season_prior_and_fpi',
            'current_games' => $samples, 'prior_games' => $priorGames,
            'minimum_prior_games' => $minimumPrior, 'risk_flags' => $flags];
    }

    private function observedBefore(mixed $value, ?CarbonImmutable $capturedAt, ?int $maximumDays = null): bool
    {
        if (! is_string($value) || trim($value) === '' || ! $capturedAt) {
            return false;
        }
        try {
            $observed = CarbonImmutable::parse($value);

            return $observed->lte($capturedAt)
                && ($maximumDays === null || $observed->gte($capturedAt->subDays($maximumDays)));
        } catch (\Throwable) {
            return false;
        }
    }
}
