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
        'elo_to_spread_divisor' => 30,
        'average_pace' => 70.0,
        'default_efficiency' => 100.0,
        'spread_to_probability_coefficient' => 4.8,

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
        'total_recent_efficiency_weight' => 0.35,
        'total_venue_efficiency_weight' => 0.15,
        'total_factor_weights' => [
            'effective_fg_pct' => 34.0,
            'free_throw_rate' => 15.0,
            'turnover_rate' => 15.0,
            'offensive_rebound_rate' => 8.0,
        ],
        'total_calibration' => [
            'pace_floor' => 62.0,
            'pace_floor_blend' => 0.5,
            'max_recent_pace_drop' => 8.0,
            'tournament_max_recent_pace_drop' => 4.0,
            'factor_adjustment_cap' => 6.0,
            'base_adjustment' => 4.0,
            'high_total_threshold' => 140.0,
            'high_total_slope' => 1.0,
            'round_of_64_base_adjustment' => 3.5,
            'round_of_64_seed_gap_threshold' => 6,
            'round_of_64_seed_gap_points' => 0.8,
            'round_of_32_base_adjustment' => 2.0,
            'round_of_32_seed_gap_points' => 0.55,
        ],

        // Vegas integration
        'vegas_weight' => 0.15,
        'model_weight_with_vegas' => 0.85,

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
            'enabled' => env('CBB_TRUE_EPA_ENABLED', false),
            'blend_weight' => env('CBB_TRUE_EPA_BLEND_WEIGHT', 0.30),
            'spread_points_per_epa' => env('CBB_TRUE_EPA_SPREAD_POINTS_PER_EPA', 15.0),
            'total_points_per_epa_component' => env('CBB_TRUE_EPA_TOTAL_POINTS_PER_COMP', 25.0),
            'min_predicted_total' => env('CBB_TRUE_EPA_MIN_TOTAL', 110.0),
            'max_predicted_total' => env('CBB_TRUE_EPA_MAX_TOTAL', 190.0),
        ],
        'live_possession' => [
            'enabled' => env('CBB_LIVE_POSSESSION_ENABLED', true),
            'tempo_blend_weight' => env('CBB_LIVE_POSSESSION_TEMPO_BLEND', 0.55),
            'pregame_margin_weight' => env('CBB_LIVE_POSSESSION_PREGAME_MARGIN', 0.40),
            'efficiency_margin_weight' => env('CBB_LIVE_POSSESSION_EFF_MARGIN', 0.90),
            'late_game_ppp_weight' => env('CBB_LIVE_POSSESSION_LATE_GAME_PPP', 0.60),
            'live_total_metrics_weight' => env('CBB_LIVE_POSSESSION_TOTAL_WEIGHT', 0.65),
            'minimum_sample_possessions' => env('CBB_LIVE_POSSESSION_MIN_SAMPLE', 40),
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
        'random_seed' => env('CBB_TOURNAMENT_RANDOM_SEED'),
        'at_large_noise_stddev' => 0.35,
        'conference_tournament_upset_factor' => 0.45,
        'enable_first_four' => true,

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
            'spread' => 2.0,       // Points, default/home-side threshold
            'spread_away' => 4.0,  // Points, stricter threshold for away ATS recommendations
            'total' => 2.25,       // Points
            'moneyline' => 0.035,  // Probability (3.5%)
        ],

        'filters' => [
            'big_dog_line_threshold' => 15.0,
            'big_dog_min_edge' => 6.0,
            'tournament_under_min_edge' => 4.5,
            'tournament_under_market_total_floor' => 145.0,
            'tournament_under_skip_edge' => 18.0,
        ],

        // Kelly Criterion bet sizing
        'kelly' => [
            'fraction' => 0.25,   // Quarter Kelly (conservative)
            'max_percent' => 5.0, // Maximum recommended bet size
        ],
    ],

];
