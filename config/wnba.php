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
    | WNBA season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('WNBA_DEFAULT_SEASON', (int) date('Y')),
        'types' => [
            'preseason' => 1,
            'regular' => 2,
            'postseason' => 3,
            'allstar' => 4,
        ],
        'type_names' => [
            'preseason' => 'Preseason',
            'regular' => 'Regular Season',
            'postseason' => 'Postseason',
            'allstar' => 'All-Star',
        ],
        'default_team_metrics_type' => env('WNBA_DEFAULT_TEAM_METRICS_TYPE', 2),
        'games' => [
            'regular_season' => 40,
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
            'requests_per_minute' => env('WNBA_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('WNBA_API_DELAY_MS', 100),
        ],
        'timeout' => env('WNBA_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('WNBA_API_RETRY_ENABLED', true),
            'max_attempts' => env('WNBA_API_RETRY_ATTEMPTS', 3),
            'delay' => env('WNBA_API_RETRY_DELAY', 1000),
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
        'queue' => env('WNBA_SYNC_QUEUE', 'default'),
        'batch_size' => env('WNBA_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('WNBA_SYNC_JOB_TIMEOUT', 300),
        'current_week_days_before' => 7,
        'current_week_days_after' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to WNBA teams.
    |
    */

    'teams' => [
        'count' => env('WNBA_TEAM_COUNT', 15),
        'conferences' => [
            'eastern' => 'Eastern',
            'western' => 'Western',
        ],
        'teams_per_conference' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Possession Estimation
    |--------------------------------------------------------------------------
    |
    | Dean Oliver's possession formula coefficient for WNBA.
    |
    */

    'possession_coefficient' => 0.44,

    /*
    |--------------------------------------------------------------------------
    | Elo Rating Configuration
    |--------------------------------------------------------------------------
    |
    | Constants for the Elo rating calculation system. These values are
    | calibrated for WNBA basketball specifically.
    |
    */

    'elo' => [
        // Default starting Elo for new teams
        'default' => 1500,

        // Base K-factor determines how much ratings change per game
        // Higher values = more volatile ratings (WNBA has fewer games)
        'base_k_factor' => 25,

        // Playoff games have higher stakes, so ratings change more
        'playoff_multiplier' => 1.5,

        // Home court advantage expressed in Elo points
        // WNBA has slightly less home court advantage than NBA
        'home_court_advantage' => 80,

        // Margin of victory multipliers give more weight to blowouts
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
        'metric_spread_weight' => env('WNBA_METRIC_SPREAD_WEIGHT', 0.25),
        'metric_spread_min_games' => env('WNBA_METRIC_SPREAD_MIN_GAMES', 10),
        'spread_output_regression_weight' => env('WNBA_SPREAD_OUTPUT_REGRESSION_WEIGHT', 0.08),

        // Average WNBA pace (possessions per game)
        'average_pace' => 88.0,

        // League average efficiency (points per 100 possessions)
        // WNBA typically has lower scoring than NBA
        'default_efficiency' => 98.0,
        'average_total' => env('WNBA_AVERAGE_TOTAL', 166.5),
        'total_tempo_regression_weight' => env('WNBA_TOTAL_TEMPO_REGRESSION_WEIGHT', 0.50),
        'total_output_regression_weight' => env('WNBA_TOTAL_OUTPUT_REGRESSION_WEIGHT', 0.25),
        'use_previous_season_metrics_fallback' => env('WNBA_USE_PREVIOUS_SEASON_METRICS_FALLBACK', true),

        // Logistic function coefficient for win probability
        'spread_to_probability_coefficient' => env('WNBA_SPREAD_TO_PROBABILITY_COEFFICIENT', 6.5),

        // Confidence score components (sum to 100 max)
        'confidence' => [
            'base' => 30,              // Having any Elo data
            'home_metrics' => 20,      // Home team has metrics
            'away_metrics' => 20,      // Away team has metrics
            'home_non_default_elo' => 15, // Home team played games
            'away_non_default_elo' => 15, // Away team played games
        ],

        // Injury adjustments
        'injury_out_spread_penalty' => 0.75,
        'injury_questionable_spread_penalty' => 0.30,
        'injury_out_total_penalty' => 0.40,
        'injury_questionable_total_penalty' => 0.15,
        'injury_epa_weighting_enabled' => true,
        'injury_epa_profile' => 'nba',
        'injury_epa_lookback_games' => 10,
        'injury_epa_baseline' => 11.5,
        'injury_epa_min_multiplier' => 0.50,
        'injury_epa_max_multiplier' => 2.00,
        'injury_epa_fallback_multiplier' => 1.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Betting Value Configuration
    |--------------------------------------------------------------------------
    |
    | Thresholds for surfacing WNBA betting recommendations from the shared
    | market-value calculator. WNBA markets can be thinner than NBA markets, so
    | these stay conservative enough to avoid tiny stale-line edges.
    |
    */

    'betting' => [
        'edge_thresholds' => [
            'spread' => env('WNBA_BETTING_SPREAD_EDGE_THRESHOLD', 2.0),
            'total' => env('WNBA_BETTING_TOTAL_EDGE_THRESHOLD', 3.5),
            'moneyline' => env('WNBA_BETTING_MONEYLINE_EDGE_THRESHOLD', 0.05),
        ],
        'spread_gate' => [
            'enabled' => env('WNBA_SPREAD_GATE_ENABLED', true),
            'validated_min_edge' => env('WNBA_SPREAD_GATE_VALIDATED_MIN_EDGE', 3.0),
            'validated_max_edge' => env('WNBA_SPREAD_GATE_VALIDATED_MAX_EDGE', 5.0),
            'underdog_min_edge' => env('WNBA_SPREAD_GATE_UNDERDOG_MIN_EDGE', 2.5),
            'underdog_max_edge' => env('WNBA_SPREAD_GATE_UNDERDOG_MAX_EDGE', 5.0),
            'block_favorite_confidence' => env('WNBA_SPREAD_GATE_BLOCK_FAVORITE_CONFIDENCE', 80.0),
        ],
    ],

];
