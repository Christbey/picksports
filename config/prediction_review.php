<?php

use App\Support\SportCatalog;

return [
    'enabled' => (bool) env('PREDICTION_REVIEW_ENABLED', false),
    'sports' => collect(SportCatalog::ALL)->mapWithKeys(fn (string $sport): array => [$sport => (bool) env('PREDICTION_REVIEW_'.strtoupper($sport).'_ENABLED', false)])->all(),
    'nba_provenance_reuse_enabled' => (bool) env('NBA_REVIEW_PROVENANCE_REUSE_ENABLED', false),
    'provider' => env('PREDICTION_REVIEW_PROVIDER', 'openai'),
    'model' => env('PREDICTION_REVIEW_MODEL', 'gpt-4o-mini'),
    'prompt_version' => 'prediction-review-v1',
    'policy_version' => 'prediction-review-decision-v1',
    'timeout_seconds' => 30,
    'lease_seconds' => 180,
    'valid_minutes' => 360,
    'max_attempts' => 3,
    'retry_seconds' => 60,
    'unknown_usage_retry_seconds' => 3600,
    'days_forward' => 7,
    'per_sport_batch' => 4,
    'budget' => [
        'application_daily_usd' => 5.0,
        'sport_daily_usd' => 1.0,
        'event_daily_usd' => 0.25,
        'reservation_usd' => 0.05,
        'event_daily_attempts' => 3,
        'pregame_reserved_attempts' => 1,
        'pregame_reserve_hours' => 12,
    ],
];
