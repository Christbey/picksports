<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Game Status Constants
    |--------------------------------------------------------------------------
    |
    | ESPN API game status values used throughout the application.
    |
    */

    'statuses' => [
        'scheduled' => 'STATUS_SCHEDULED',
        'in_progress' => 'STATUS_IN_PROGRESS',
        'final' => 'STATUS_FINAL',
        'postponed' => 'STATUS_POSTPONED',
        'canceled' => 'STATUS_CANCELED',
        'suspended' => 'STATUS_SUSPENDED',
    ],

    /*
    |--------------------------------------------------------------------------
    | Season Configuration
    |--------------------------------------------------------------------------
    |
    | CFB season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('CFB_DEFAULT_SEASON', 2025),
        'types' => [
            'preseason' => 1,
            'regular' => 2,
            'postseason' => 3,
        ],
        'weeks' => [
            'preseason' => 1,
            'regular' => 16,
            'postseason' => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ESPN API Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for ESPN API integration.
    |
    */

    'api' => [
        'rate_limit' => [
            'requests_per_minute' => env('CFB_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('CFB_API_DELAY_MS', 100),
        ],
        'timeout' => env('CFB_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('CFB_API_RETRY_ENABLED', true),
            'max_attempts' => env('CFB_API_RETRY_ATTEMPTS', 3),
            'delay' => env('CFB_API_RETRY_DELAY', 1000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for data synchronization jobs.
    |
    */

    'sync' => [
        'queue' => env('CFB_SYNC_QUEUE', 'default'),
        'batch_size' => env('CFB_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('CFB_SYNC_JOB_TIMEOUT', 300),
        'current_week_days_before' => 3,
        'current_week_days_after' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to CFB teams.
    |
    */

    'teams' => [
        'divisions' => [
            'fbs' => 'FBS',
            'fcs' => 'FCS',
        ],
        'conferences' => [
            'fbs_count' => 10,
            'fcs_count' => 13,
        ],
        'power_conferences' => [
            'Southeastern Conference',
            'Big Ten Conference',
            'Big 12 Conference',
            'Atlantic Coast Conference',
        ],
        'group_of_five_conferences' => [
            'American Athletic Conference',
            'Conference USA',
            'Mid-American Conference',
            'Mountain West Conference',
            'Sun Belt Conference',
        ],
    ],

    'season_affiliations' => [
        'overrides' => [
            'NDSU' => [
                [
                    'end_season' => 2025,
                    'subdivision' => 'FCS',
                ],
                [
                    'start_season' => 2026,
                    'subdivision' => 'FBS',
                    'conference' => 'Mountain West Conference',
                    'division' => 'FBS',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ELO Rating System Configuration
    |--------------------------------------------------------------------------
    |
    | These parameters control the ELO rating calculation for CFB teams.
    | Values have been calibrated for college football dynamics.
    |
    */

    'elo' => [
        /**
         * Default starting ELO rating for all teams
         */
        'default_rating' => 1500,

        /**
         * Offseason Elo regression toward mean before a new season starts.
         */
        'offseason_regression_factor' => 0.30,

        /**
         * Base K-factor for regular season games
         * Controls how much ratings change after each game
         */
        'base_k_factor' => 20,

        /**
         * Home field advantage in ELO points
         * College football typically has strong home field advantage
         */
        'home_field_advantage' => 55,

        /**
         * K-factor multiplier for playoff/bowl games
         * Postseason games have higher impact on ratings
         */
        'playoff_multiplier' => 1.5,

        /**
         * K-factor multiplier for early season games (weeks 1-4)
         * Higher volatility as teams establish identity
         */
        'recency_multiplier' => 1.2,

        /**
         * Early season weeks threshold for recency multiplier
         */
        'recency_weeks' => 4,

        /**
         * Margin of victory coefficient
         * Used in logarithmic MOV formula: 1.0 + (log(margin + 1) * coefficient)
         */
        'mov_coefficient' => 0.25,

        /**
         * Maximum margin of victory multiplier
         * Prevents blowouts from dominating too much
         */
        'max_mov_multiplier' => 2.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prediction Configuration
    |--------------------------------------------------------------------------
    |
    | Parameters for generating game predictions and spreads.
    |
    */

    'predictions' => [
        /**
         * ELO points per predicted point spread
         * Calibrated to minimize spread prediction error
         */
        'points_per_elo' => 0.08,

        /**
         * Maximum predicted spread (points)
         * CFB spreads can be large due to talent disparity
         */
        'max_spread' => 40,

        /**
         * Minimum predicted spread (points)
         */
        'min_spread' => -40,

        /**
         * Average points per game for total estimation
         * Used as baseline when Elo-only prediction
         */
        'average_total' => 52,

        /**
         * Bounds for total projections.
         */
        'min_total' => 28,
        'max_total' => 88,

        /**
         * Advanced metric weights for CFB spread/total shaping.
         */
        'fpi_spread_weight' => 0.18,
        'wepa_spread_weight' => 4.5,
        'efficiency_spread_weight' => 0.04,
        'wepa_total_offense_weight' => 2.2,
        'wepa_total_defense_weight' => 1.4,
        'fpi_total_weight' => 0.08,

        /**
         * When current-season team metrics do not exist yet, reuse the latest
         * prior-season metrics for preseason / early schedule predictions.
         */
        'use_previous_season_metrics_fallback' => true,

        /**
         * Use the latest same-season Elo before kickoff, falling back to a
         * regressed prior-season Elo for Week 0 through the early season.
         */
        'use_previous_season_elo_fallback' => env('CFB_USE_PREVIOUS_SEASON_ELO_FALLBACK', true),
        'previous_season_elo_fallback_through_week' => env('CFB_PREVIOUS_SEASON_ELO_FALLBACK_THROUGH_WEEK', 4),
        'previous_season_elo_regression_factor' => env(
            'CFB_PREVIOUS_SEASON_ELO_REGRESSION_FACTOR',
            env('CFB_OFFSEASON_ELO_REGRESSION_FACTOR', 0.30)
        ),

        /**
         * Model version for tracking prediction algorithm changes
         */
        'model_version' => '1.1',

        /**
         * Confidence scoring parameters
         */
        'confidence' => [
            'base' => 50,
            'home_non_default_elo' => 25,
            'away_non_default_elo' => 25,
        ],

        /**
         * Injury adjustments
         */
        'injury_out_spread_penalty' => 0.50,
        'injury_questionable_spread_penalty' => 0.20,
        'injury_out_total_penalty' => 0.30,
        'injury_questionable_total_penalty' => 0.10,
    ],

];
