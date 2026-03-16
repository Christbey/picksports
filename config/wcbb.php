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
            'Atlantic Coast Conference',
            'Big 12',
            'Big 12 Conference',
            'Big Ten',
            'Big Ten Conference',
            'Big East',
            'Big East Conference',
            'Pac-12',
            'Pac-12 Conference',
            'SEC',
            'Southeastern Conference',
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
        'elo_to_spread_divisor' => 30,
        'average_pace' => 70.0,
        'default_efficiency' => 100.0,
        'spread_to_probability_coefficient' => 4.0,

        // Ensemble weights (sum to 1.0)
        'elo_weight' => 0.25,
        'efficiency_weight' => 0.40,
        'form_weight' => 0.35,

        // Recent form
        'recent_form_games' => 10,
        'recency_decay' => 0.9,

        // Rest days
        'rest_day_adjustment' => 1.0,
        'back_to_back_penalty' => -1.5,

        // Situational weights
        'home_away_split_weight' => 0.15,
        'turnover_diff_weight' => 0.4,
        'rebound_margin_weight' => 0.25,

        // Vegas integration
        'vegas_weight' => 0.25,
        'model_weight_with_vegas' => 0.75,

        // Home court
        'home_court_points' => 3.5,

        // Injury adjustments
        'injury_out_spread_penalty' => 0.75,
        'injury_questionable_spread_penalty' => 0.30,
        'injury_out_total_penalty' => 0.40,
        'injury_questionable_total_penalty' => 0.15,
        'injury_epa_weighting_enabled' => true,
        'injury_epa_profile' => 'cbb',
        'injury_epa_lookback_games' => 10,
        'injury_epa_baseline' => 11.0,
        'injury_epa_min_multiplier' => 0.50,
        'injury_epa_max_multiplier' => 2.00,
        'injury_epa_fallback_multiplier' => 1.00,

        // Guarded rollout for true play-by-play EPA blend.
        'true_epa' => [
            'enabled' => env('WCBB_TRUE_EPA_ENABLED', false),
            'blend_weight' => env('WCBB_TRUE_EPA_BLEND_WEIGHT', 0.30),
            'spread_points_per_epa' => env('WCBB_TRUE_EPA_SPREAD_POINTS_PER_EPA', 15.0),
            'total_points_per_epa_component' => env('WCBB_TRUE_EPA_TOTAL_POINTS_PER_COMP', 25.0),
            'min_predicted_total' => env('WCBB_TRUE_EPA_MIN_TOTAL', 110.0),
            'max_predicted_total' => env('WCBB_TRUE_EPA_MAX_TOTAL', 190.0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tournament Forecast Configuration
    |--------------------------------------------------------------------------
    |
    | Parameters for projecting NCAA tournament bids and championship odds.
    | This model is intentionally lightweight and designed for iterative tuning.
    |
    */
    'tournament_forecast' => [
        // Selection settings
        'field_size' => 68,
        'auto_bids' => 31,
        'bubble_steepness' => 2.5,
        'selection_zscore_boost' => 1.5,
        'auto_bid_probability_floor' => 0.96,
        'in_field_probability_floor' => 0.55,
        'outside_field_probability_ceiling' => 0.45,

        // Monte Carlo simulation
        'simulations' => 5000,
        'selection_sampling_exponent' => 1.35,
        'random_seed' => env('WCBB_TOURNAMENT_RANDOM_SEED'),
        'at_large_noise_stddev' => 0.35,
        'conference_tournament_upset_factor' => 0.45,
        'enable_first_four' => true,
        'conference_strength_top_teams' => 3,
        'selection_conference_strength_weight' => 0.35,
        'selection_power_conference_bonus' => 0.45,
        'selection_resume_confidence_penalty' => 0.30,
        'selection_full_confidence_games' => 20,
        'champion_conference_strength_weight' => 0.12,
        'champion_power_conference_bonus' => 0.08,
        'refresh' => [
            'enabled' => env('WCBB_TOURNAMENT_REFRESH_ENABLED', true),
            'minimum_coverage_ratio' => env('WCBB_TOURNAMENT_REFRESH_MIN_COVERAGE_RATIO', 0.95),
            'stale_after_hours' => env('WCBB_TOURNAMENT_REFRESH_STALE_AFTER_HOURS', 6),
        ],

        // Selection score weights
        'selection_weights' => [
            'adj_net_rating' => 0.30,
            'rolling_net_rating' => 0.20,
            'strength_of_schedule' => 0.20,
            'elo_rating' => 0.15,
            'win_pct' => 0.15,
        ],

        // Head-to-head game simulation weights
        'champion_weights' => [
            'elo_rating' => 0.45,
            'adj_net_rating' => 0.25,
            'rolling_net_rating' => 0.10,
            'win_pct' => 0.10,
            'strength_of_schedule' => 0.10,
        ],
    ],

];
