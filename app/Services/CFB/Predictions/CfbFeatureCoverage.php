<?php

namespace App\Services\CFB\Predictions;

/** Describes executed canonical features; populated legacy tables are not evidence of use. */
class CfbFeatureCoverage
{
    public static function describe(array $inputs, bool $fpiBaseline, ?string $pairedRating): array
    {
        $both = static fn (string $field): bool => is_numeric(data_get($inputs, 'home.metrics.'.$field))
            && is_numeric(data_get($inputs, 'away.metrics.'.$field));

        return [
            'spread_baseline' => $fpiBaseline ? 'fpi_points' : 'elo_scoring',
            'elo' => ['role' => $fpiBaseline ? 'comparison_and_injury_reference' : 'spread_baseline',
                'home_evidence' => data_get($inputs, 'home.elo_evidence'),
                'away_evidence' => data_get($inputs, 'away.elo_evidence')],
            'paired_rating_family' => $pairedRating,
            'local_context' => collect(['recent_form_rating', 'turnover_differential', 'rest_travel_fatigue', 'injury_adjusted_team_rating'])
                ->mapWithKeys(fn ($field) => [$field => $both($field) ? 'both_teams_available' : 'missing_or_partial_zero_fallback'])->all(),
            'injury_rating_evidence' => ['home' => data_get($inputs, 'home.injury_rating_evidence'),
                'away' => data_get($inputs, 'away.injury_rating_evidence')],
            'injury_record_counts' => ['home' => count(data_get($inputs, 'home.injuries', [])),
                'away' => count(data_get($inputs, 'away.injuries', []))],
            'empty_injury_records_mean' => 'no_stored_active_records_not_confirmed_healthy',
            'eligibility_only' => ['quarterback_evidence', 'prior_metric_provenance', 'rating_provenance'],
            'not_directly_applied' => ['returning_production', 'recruiting_talent', 'transfer_portal',
                'coaching_changes', 'wepa_matchups', 'weather', 'timezone_direction'],
            'external_fpi_feature_coverage' => 'not_locally_auditable',
            'calibration' => ['cover_probability' => null, 'ats_status' => 'not_calibrated',
                'moneyline_status' => 'uncalibrated_logistic_margin_transform',
                'eligibility_is_accuracy_validation' => false],
        ];
    }
}
