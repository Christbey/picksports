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
         * Model version for tracking prediction algorithm changes
         */
        'model_version' => '1.0',

        /**
         * Confidence scoring parameters
         */
        'confidence' => [
            'base' => 50,
            'home_non_default_elo' => 25,
            'away_non_default_elo' => 25,
        ],
    ],

];
