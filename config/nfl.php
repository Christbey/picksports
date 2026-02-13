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
    | NFL season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('NFL_DEFAULT_SEASON', 2025),
        'types' => [
            'preseason' => 1,
            'regular' => 2,
            'postseason' => 3,
        ],
        'weeks' => [
            'preseason' => 4,
            'regular' => 18,
            'postseason' => 5,
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
            'requests_per_minute' => env('NFL_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('NFL_API_DELAY_MS', 100),
        ],
        'timeout' => env('NFL_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('NFL_API_RETRY_ENABLED', true),
            'max_attempts' => env('NFL_API_RETRY_ATTEMPTS', 3),
            'delay' => env('NFL_API_RETRY_DELAY', 1000),
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
        'queue' => env('NFL_SYNC_QUEUE', 'default'),
        'batch_size' => env('NFL_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('NFL_SYNC_JOB_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to NFL teams.
    |
    */

    'teams' => [
        'count' => 32,
        'conferences' => [
            'afc' => 'AFC',
            'nfc' => 'NFC',
        ],
        'divisions_per_conference' => 4,
        'teams_per_division' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | ELO Rating System Configuration
    |--------------------------------------------------------------------------
    |
    | These parameters control the ELO rating calculation for NFL teams.
    | Values have been calibrated against historical game data.
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
        'base_k_factor' => 16,

        /**
         * Home field advantage in ELO points
         * Calibrated from 2025 season data
         */
        'home_field_advantage' => 25,

        /**
         * K-factor multiplier for playoff games
         * Playoff games have higher impact on ratings
         */
        'playoff_multiplier' => 1.5,

        /**
         * K-factor multiplier for early season games (weeks 1-4)
         * Higher volatility as teams establish identity
         */
        'recency_multiplier' => 1.1,

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
        'max_mov_multiplier' => 2.2,
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
        'points_per_elo' => 0.09,

        /**
         * Maximum predicted spread (points)
         * NFL spreads rarely exceed ±15 points
         */
        'max_spread' => 15,

        /**
         * Minimum predicted spread (points)
         */
        'min_spread' => -15,

        /**
         * Average NFL game total (combined score)
         * Used as baseline for over/under predictions
         */
        'average_total' => 44.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Betting Value Configuration
    |--------------------------------------------------------------------------
    |
    | Thresholds and parameters for betting value detection.
    |
    */

    'betting' => [
        // Minimum edge required to generate a recommendation
        'edge_thresholds' => [
            'spread' => 2.5,      // Points
            'total' => 3.0,       // Points
            'moneyline' => 0.05,  // Probability (5%)
        ],

        // Kelly Criterion bet sizing
        'kelly' => [
            'fraction' => 0.25,   // Quarter Kelly (conservative)
            'max_percent' => 5.0, // Maximum recommended bet size
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Calibration Defaults
    |--------------------------------------------------------------------------
    |
    | Default parameter ranges for calibration commands.
    |
    */

    'calibration' => [
        'spread' => [
            'min' => 0.01,
            'max' => 0.15,
            'step' => 0.005,
        ],

        'hfa' => [
            'min' => 0,
            'max' => 100,
            'step' => 5,
        ],

        'all_parameters' => [
            'quick' => [
                'hfa' => [25, 30, 35],
                'k_factor' => [18, 20, 22],
                'playoff_mult' => [1.4, 1.5, 1.6],
                'recency_mult' => [1.2, 1.3, 1.4],
                'mov_coef' => [0.20, 0.25, 0.30],
            ],
            'full' => [
                'hfa' => [20, 25, 30, 35, 40],
                'k_factor' => [16, 18, 20, 22, 24],
                'playoff_mult' => [1.3, 1.4, 1.5, 1.6, 1.7],
                'recency_mult' => [1.1, 1.2, 1.3, 1.4, 1.5],
                'mov_coef' => [0.15, 0.20, 0.25, 0.30, 0.35],
            ],
        ],
    ],
];
