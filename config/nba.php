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

        // Ensemble weights (must sum to 1.0)
        'elo_weight' => 0.30,
        'efficiency_weight' => 0.40,
        'form_weight' => 0.30,

        // Recent form
        'recent_form_games' => 10,
        'recency_decay' => 0.9,

        // Rest days
        'rest_day_adjustment' => 1.5,
        'back_to_back_penalty' => -2.0,

        // Home/away split
        'home_away_split_weight' => 0.15,

        // Turnover & rebound
        'turnover_diff_weight' => 0.5,
        'rebound_margin_weight' => 0.3,

        // Vegas integration
        'vegas_weight' => 0.25,
        'model_weight_with_vegas' => 0.75,

        // Home court (efficiency-based)
        'home_court_points' => 3.0,

        // Injury adjustments
        'injury_out_spread_penalty' => 0.75,
        'injury_questionable_spread_penalty' => 0.30,
        'injury_out_total_penalty' => 0.40,
        'injury_questionable_total_penalty' => 0.15,
        'injury_epa_weighting_enabled' => true,
        'injury_epa_profile' => 'nba',
        'injury_epa_lookback_games' => 10,
        'injury_epa_baseline' => 12.0,
        'injury_epa_min_multiplier' => 0.50,
        'injury_epa_max_multiplier' => 2.00,
        'injury_epa_fallback_multiplier' => 1.00,

        // Narrative generation settings for prediction summaries.
        'narrative' => [
            'provider' => env('NBA_PREDICTION_NARRATIVE_PROVIDER', 'template'),
            'model' => env('NBA_PREDICTION_NARRATIVE_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
            'temperature' => env('NBA_PREDICTION_NARRATIVE_TEMPERATURE', 0.2),
            'max_tokens' => env('NBA_PREDICTION_NARRATIVE_MAX_TOKENS', 220),
            'timeout_seconds' => env('NBA_PREDICTION_NARRATIVE_TIMEOUT_SECONDS', 8),
            'trends_sample_size' => env('NBA_PREDICTION_NARRATIVE_TRENDS_SAMPLE_SIZE', 16),
            'trends_tier' => env('NBA_PREDICTION_NARRATIVE_TRENDS_TIER', 'basic'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Playoff Forecast Configuration
    |--------------------------------------------------------------------------
    |
    | Lightweight projection settings for NBA postseason futures.
    |
    */

    'playoff_forecast' => [
        'simulations' => 500,
        'playoff_teams_per_conference' => 8,
        'play_in_teams_per_conference' => 10,
        'division_winner_bonus' => 0.20,
        'rank_noise_std' => 0.35,
        'conference_finals_base' => 0.42,
        'finals_seed_penalty' => 0.06,
        'selection_weights' => [
            'net_rating' => 0.40,
            'elo_rating' => 0.30,
            'win_pct' => 0.20,
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
