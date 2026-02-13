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
    | NBA season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('NBA_DEFAULT_SEASON', 2025),
        'types' => [
            'preseason' => 1,
            'regular' => 2,
            'postseason' => 3,
            'allstar' => 4,
        ],
        'games' => [
            'regular_season' => 82,
            'playoff_rounds' => 4,
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
            'requests_per_minute' => env('NBA_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('NBA_API_DELAY_MS', 100),
        ],
        'timeout' => env('NBA_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('NBA_API_RETRY_ENABLED', true),
            'max_attempts' => env('NBA_API_RETRY_ATTEMPTS', 3),
            'delay' => env('NBA_API_RETRY_DELAY', 1000),
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
        'queue' => env('NBA_SYNC_QUEUE', 'default'),
        'batch_size' => env('NBA_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('NBA_SYNC_JOB_TIMEOUT', 300),
        'current_week_days_before' => 7,
        'current_week_days_after' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to NBA teams.
    |
    */

    'teams' => [
        'count' => 30,
        'conferences' => [
            'eastern' => 'Eastern',
            'western' => 'Western',
        ],
        'divisions_per_conference' => 3,
        'teams_per_division' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Possession Estimation
    |--------------------------------------------------------------------------
    |
    | Dean Oliver's possession formula coefficient for NBA.
    | Formula: Poss = FGA - ORB + TO + (coefficient * FTA)
    |
    */

    'possession_coefficient' => 0.44,

    /*
    |--------------------------------------------------------------------------
    | Elo Rating Configuration
    |--------------------------------------------------------------------------
    |
    | Constants for the Elo rating calculation system. These values are
    | calibrated for NBA basketball specifically.
    |
    */

    'elo' => [
        // Default starting Elo for new teams
        'default' => 1500,

        // Base K-factor determines how much ratings change per game
        // Higher values = more volatile ratings
        'base_k_factor' => 20,

        // Playoff games have higher stakes, so ratings change more
        'playoff_multiplier' => 1.5,

        // Home court advantage expressed in Elo points
        // ~100 Elo points ≈ 3.5 point spread advantage
        'home_court_advantage' => 100,

        // Margin of victory multipliers give more weight to blowouts
        // with diminishing returns for very large margins
        'margin_multipliers' => [
            'close' => ['max_margin' => 3, 'multiplier' => 1.0],
            'moderate' => ['max_margin' => 10, 'multiplier' => 1.2],
            'decisive' => ['max_margin' => 20, 'multiplier' => 1.5],
            'blowout' => ['max_margin' => null, 'multiplier' => 1.75],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prediction Model Configuration
    |--------------------------------------------------------------------------
    |
    | Constants for generating game predictions from Elo ratings and
    | team efficiency metrics.
    |
    */

    'prediction' => [
        // Elo points per point of spread
        // Calibrated so 28 Elo = 1 point spread
        'elo_to_spread_divisor' => 28,

        // Average NBA pace (possessions per game)
        'average_pace' => 100.0,

        // League average efficiency (points per 100 possessions)
        // Used as fallback when team metrics unavailable
        'default_efficiency' => 110.0,

        // Logistic function coefficient for win probability
        // Calibrated so 7-point spread ≈ 70% win probability
        'spread_to_probability_coefficient' => 4,

        // Confidence score components (sum to 100 max)
        'confidence' => [
            'base' => 30,              // Having any Elo data
            'home_metrics' => 20,      // Home team has metrics
            'away_metrics' => 20,      // Away team has metrics
            'home_non_default_elo' => 15, // Home team played games
            'away_non_default_elo' => 15, // Away team played games
        ],
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
            'spread' => 2.0,      // Points
            'total' => 4.0,       // Points
            'moneyline' => 0.05,  // Probability (5%)
        ],

        // Kelly Criterion bet sizing
        'kelly' => [
            'fraction' => 0.25,   // Quarter Kelly (conservative)
            'max_percent' => 5.0, // Maximum recommended bet size
        ],
    ],

];
