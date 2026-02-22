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
    | CBB season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('CBB_DEFAULT_SEASON', 2026),
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
            'requests_per_minute' => env('CBB_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('CBB_API_DELAY_MS', 100),
        ],
        'timeout' => env('CBB_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('CBB_API_RETRY_ENABLED', true),
            'max_attempts' => env('CBB_API_RETRY_ATTEMPTS', 3),
            'delay' => env('CBB_API_RETRY_DELAY', 1000),
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
        'queue' => env('CBB_SYNC_QUEUE', 'default'),
        'batch_size' => env('CBB_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('CBB_SYNC_JOB_TIMEOUT', 300),
        'current_week_days_before' => 3,
        'current_week_days_after' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to CBB teams and conferences.
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
            'Big East',
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
    | thresholds. Values have been tuned against KenPom benchmarks.
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
        | Lower values expand the opponent network but may reduce accuracy.
        |
        | Recommended: 5-8 games
        | Current: 5 games (78 qualifying teams, MAE 6.57 vs KenPom)
        |
        */
        'minimum_games' => env('CBB_MINIMUM_GAMES', 5),

        /*
        |--------------------------------------------------------------------------
        | Possession Coefficient
        |--------------------------------------------------------------------------
        |
        | Used in Dean Oliver's possession formula to estimate possessions from
        | box score statistics. CBB-optimized value differs from NBA (0.44).
        |
        | Formula: Poss = FGA - ORB + TO + (coefficient * FTA)
        |
        | Tuned via comparative analysis against KenPom data (MAE 6.27)
        |
        */
        'possession_coefficient' => env('CBB_POSSESSION_COEFFICIENT', 0.40),

        /*
        |--------------------------------------------------------------------------
        | Rolling Window Size
        |--------------------------------------------------------------------------
        |
        | Number of recent games to analyze for rolling metrics (recent form).
        | Useful for tracking momentum and recent performance trends.
        |
        */
        'rolling_window_size' => env('CBB_ROLLING_WINDOW_SIZE', 10),

        /*
        |--------------------------------------------------------------------------
        | Opponent Adjustment - Maximum Iterations
        |--------------------------------------------------------------------------
        |
        | Maximum iterations for the iterative convergence algorithm when
        | calculating opponent-adjusted efficiency ratings. The algorithm
        | iteratively refines team ratings based on opponent strength.
        |
        */
        'max_adjustment_iterations' => env('CBB_MAX_ADJUSTMENT_ITERATIONS', 10),

        /*
        |--------------------------------------------------------------------------
        | Opponent Adjustment - Convergence Threshold
        |--------------------------------------------------------------------------
        |
        | The maximum change in efficiency ratings between iterations before
        | the algorithm is considered converged. Lower values increase precision
        | but may require more iterations.
        |
        */
        'adjustment_convergence_threshold' => env('CBB_ADJUSTMENT_CONVERGENCE_THRESHOLD', 0.1),

        /*
        |--------------------------------------------------------------------------
        | Opponent Adjustment - Damping Factor
        |--------------------------------------------------------------------------
        |
        | Controls how quickly the iterative adjustment algorithm converges.
        | Value between 0 and 1 where:
        | - Lower values (0.2-0.3): Slower convergence, more stable
        | - Higher values (0.5-0.7): Faster convergence, may oscillate
        |
        | Current: 0.4 (37.3% improvement over single-pass, MAE 6.57 vs KenPom)
        |
        */
        'adjustment_damping_factor' => env('CBB_ADJUSTMENT_DAMPING_FACTOR', 0.4),

    ],

    /*
    |--------------------------------------------------------------------------
    | Normalization Baseline
    |--------------------------------------------------------------------------
    |
    | Target value for normalized adjusted metrics. Following KenPom methodology,
    | both offensive and defensive efficiency are normalized to this baseline
    | after opponent adjustments.
    |
    */
    'normalization_baseline' => 100.0,

    /*
    |--------------------------------------------------------------------------
    | Elo Rating Configuration
    |--------------------------------------------------------------------------
    |
    | Constants for the Elo rating calculation system. These values are
    | calibrated for college basketball specifically.
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
        // Lower than NBA (100) due to more neutral site games
        'home_court_advantage' => 35,

        // Margin of victory multipliers
        // CBB has different margin dynamics than NBA
        'margin_multipliers' => [
            'close' => ['max_margin' => 5, 'multiplier' => 1.0],
            'moderate' => ['max_margin' => 12, 'multiplier' => 1.2],
            'decisive' => ['max_margin' => 20, 'multiplier' => 1.5],
            'blowout' => ['max_margin' => null, 'multiplier' => 1.75],
        ],

        // Strength-of-schedule dampener reduces K-factor for mismatched games
        // Games between evenly-matched teams move ELO more
        'sos_adjustment' => [
            'enabled' => true,
            'divisor' => 800,
            'floor' => 0.5,
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
        // Elo points per point of spread (calibrated for CBB)
        'elo_to_spread_divisor' => 30,

        // Average CBB pace (possessions per 40 minutes)
        'average_pace' => 70.0,

        // League average efficiency (points per 100 possessions)
        'default_efficiency' => 100.0,

        // Weight for Elo vs efficiency (0.5 = equal weight)
        'elo_weight' => 0.5,

        // Home court advantage in points for efficiency calculation
        'home_court_points' => 3.5,

        // Win probability logistic denominator (higher = more conservative)
        // Calibrated so 7-point spread ≈ 78% probability
        'spread_to_probability_coefficient' => 5.5,
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

];
