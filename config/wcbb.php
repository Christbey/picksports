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
    | WCBB season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('WCBB_DEFAULT_SEASON', 2026),
        'types' => [
            'preseason' => 1,
            'regular' => 2,
            'postseason' => 3,
        ],
        'tournament' => [
            'teams' => 68,
            'rounds' => 6,
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
            'requests_per_minute' => env('WCBB_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('WCBB_API_DELAY_MS', 100),
        ],
        'timeout' => env('WCBB_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('WCBB_API_RETRY_ENABLED', true),
            'max_attempts' => env('WCBB_API_RETRY_ATTEMPTS', 3),
            'delay' => env('WCBB_API_RETRY_DELAY', 1000),
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
        'queue' => env('WCBB_SYNC_QUEUE', 'default'),
        'batch_size' => env('WCBB_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('WCBB_SYNC_JOB_TIMEOUT', 300),
        'current_week_days_before' => 3,
        'current_week_days_after' => 3,
        'schedule_weeks_ahead' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to WCBB teams and conferences.
    |
    */

    'teams' => [
        'divisions' => [
            'd1' => 'Division I',
            'd2' => 'Division II',
            'd3' => 'Division III',
        ],
        'power_conferences' => [
            'ACC',
            'Big 12',
            'Big Ten',
            'Pac-12',
            'SEC',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Metrics Configuration
    |--------------------------------------------------------------------------
    |
    | These values configure the advanced team metrics calculation system,
    | including possession estimation, opponent adjustments, and statistical
    | thresholds. Values have been tuned for women's college basketball.
    |
    */

    'metrics' => [

        /*
        |--------------------------------------------------------------------------
        | Minimum Games Threshold
        |--------------------------------------------------------------------------
        |
        | The minimum number of completed games required before a team's metrics
        | are considered statistically valid and included in opponent adjustments.
        |
        */
        'minimum_games' => env('WCBB_MINIMUM_GAMES', 5),

        /*
        |--------------------------------------------------------------------------
        | Possession Coefficient
        |--------------------------------------------------------------------------
        |
        | Used in Dean Oliver's possession formula to estimate possessions from
        | box score statistics. WCBB-optimized value differs from NBA (0.44).
        |
        | Formula: Poss = FGA - ORB + TO + (coefficient * FTA)
        |
        */
        'possession_coefficient' => env('WCBB_POSSESSION_COEFFICIENT', 0.40),

        /*
        |--------------------------------------------------------------------------
        | Rolling Window Size
        |--------------------------------------------------------------------------
        |
        | Number of recent games to analyze for rolling metrics (recent form).
        |
        */
        'rolling_window_size' => env('WCBB_ROLLING_WINDOW_SIZE', 10),

        /*
        |--------------------------------------------------------------------------
        | Opponent Adjustment - Maximum Iterations
        |--------------------------------------------------------------------------
        |
        | Maximum iterations for the iterative convergence algorithm when
        | calculating opponent-adjusted efficiency ratings.
        |
        */
        'max_adjustment_iterations' => env('WCBB_MAX_ADJUSTMENT_ITERATIONS', 10),

        /*
        |--------------------------------------------------------------------------
        | Opponent Adjustment - Convergence Threshold
        |--------------------------------------------------------------------------
        |
        | The maximum change in efficiency ratings between iterations before
        | the algorithm is considered converged.
        |
        */
        'adjustment_convergence_threshold' => env('WCBB_ADJUSTMENT_CONVERGENCE_THRESHOLD', 0.1),

        /*
        |--------------------------------------------------------------------------
        | Opponent Adjustment - Damping Factor
        |--------------------------------------------------------------------------
        |
        | Controls how quickly the iterative adjustment algorithm converges.
        | Value between 0 and 1 where lower values = slower but stable.
        |
        */
        'adjustment_damping_factor' => env('WCBB_ADJUSTMENT_DAMPING_FACTOR', 0.4),

    ],

    /*
    |--------------------------------------------------------------------------
    | Normalization Baseline
    |--------------------------------------------------------------------------
    |
    | Target value for normalized adjusted metrics. Both offensive and
    | defensive efficiency are normalized to this baseline after adjustments.
    |
    */
    'normalization_baseline' => 100.0,

    /*
    |--------------------------------------------------------------------------
    | Elo Rating Configuration
    |--------------------------------------------------------------------------
    |
    | Constants for the Elo rating calculation system. These values are
    | calibrated for women's college basketball specifically.
    |
    */

    'elo' => [
        // Default starting Elo for new teams
        'default' => 1500,

        // Base K-factor determines how much ratings change per game
        'base_k_factor' => 20,

        // Playoff games (NCAA Tournament) have higher stakes
        'playoff_multiplier' => 1.5,

        // Home court advantage in Elo points
        'home_court_advantage' => 35,

        // Margin of victory multipliers
        'margin_multipliers' => [
            'close' => ['max_margin' => 5, 'multiplier' => 1.0],
            'moderate' => ['max_margin' => 12, 'multiplier' => 1.2],
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
        // Elo points per point of spread (calibrated for WCBB)
        'elo_to_spread_divisor' => 30,

        // Average WCBB pace (possessions per 40 minutes)
        'average_pace' => 70.0,

        // League average efficiency (points per 100 possessions)
        'default_efficiency' => 100.0,

        // Win probability logistic denominator
        // Calibrated so 7-point spread ≈ 70% probability
        'spread_to_probability_coefficient' => 4.0,
    ],

];
