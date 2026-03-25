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
        'delayed' => 'STATUS_DELAYED',
    ],

    /*
    |--------------------------------------------------------------------------
    | Season Configuration
    |--------------------------------------------------------------------------
    |
    | MLB season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('MLB_DEFAULT_SEASON', 2025),
        'types' => [
            'spring_training' => 1,
            'regular' => 2,
            'postseason' => 3,
            'allstar' => 4,
        ],
        'type_names' => [
            'preseason' => 'Preseason',
            'regular' => 'Regular Season',
            'postseason' => 'Postseason',
        ],
        'analytics_types' => [2, 3],
        'games' => [
            'regular_season' => 162,
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
            'requests_per_minute' => env('MLB_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('MLB_API_DELAY_MS', 100),
        ],
        'timeout' => env('MLB_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('MLB_API_RETRY_ENABLED', true),
            'max_attempts' => env('MLB_API_RETRY_ATTEMPTS', 3),
            'delay' => env('MLB_API_RETRY_DELAY', 1000),
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
        'queue' => env('MLB_SYNC_QUEUE', 'default'),
        'batch_size' => env('MLB_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('MLB_SYNC_JOB_TIMEOUT', 300),
        'current_week_days_before' => 7,
        'current_week_days_after' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to MLB teams.
    |
    */

    'teams' => [
        'count' => 30,
        'leagues' => [
            'american' => 'American League',
            'national' => 'National League',
        ],
        'divisions_per_league' => 3,
        'teams_per_division' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | ELO Rating System Configuration
    |--------------------------------------------------------------------------
    |
    | MLB uses a dual Elo system combining team and pitcher ratings.
    |
    */

    'elo' => [
        /**
         * Default starting ELO rating for teams and pitchers
         */
        'default_rating' => 1500,

        /**
         * Base K-factor for regular season games
         */
        'base_k_factor' => 20,

        /**
         * K-factor multiplier for playoff games
         */
        'playoff_multiplier' => 1.5,

        /**
         * Home field advantage in ELO points
         */
        'home_field_advantage' => 35,

        /**
         * Weight for team Elo in combined calculation
         */
        'team_weight' => 0.6,

        /**
         * Weight for pitcher Elo in combined calculation
         */
        'pitcher_weight' => 0.4,

        /**
         * Number of recent starts to use for pitcher Elo average
         */
        'recent_starts_limit' => 10,

        /**
         * Average runs per MLB game (for total calculation)
         */
        'average_runs_per_game' => 9.0,

        /**
         * Team Elo regression to mean during offseason
         */
        'team_regression_factor' => 0.33,

        /**
         * Pitcher Elo regression to mean during offseason
         */
        'pitcher_regression_factor' => 0.40,

        /**
         * Pitcher-specific K-factor (lower than team's base_k_factor)
         */
        'pitcher_k_factor' => 15,

        /**
         * Dampening factor for margin of victory on pitcher Elo (0.0–1.0)
         */
        'pitcher_margin_dampening' => 0.5,

        /**
         * Home field advantage for pitcher Elo (0 = no HFA for pitchers)
         */
        'pitcher_home_field_advantage' => 0,

        /**
         * Margin of victory multipliers (run differential)
         * MLB-specific: conservative since run differential is less predictive
         */
        'margin_multipliers' => [
            'close' => ['max_margin' => 2, 'multiplier' => 1.0],
            'moderate' => ['max_margin' => 5, 'multiplier' => 1.1],
            'large' => ['max_margin' => 9, 'multiplier' => 1.2],
            'blowout' => ['max_margin' => null, 'multiplier' => 1.3],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Metrics Configuration
    |--------------------------------------------------------------------------
    |
    | Configurable multipliers and scales for team metric calculations.
    |
    */

    'metrics' => [
        'offensive_rating' => [
            'runs_multiplier' => env('MLB_OFFENSIVE_RUNS_MULT', 20),
            'batting_avg_multiplier' => env('MLB_OFFENSIVE_BA_MULT', 100),
            'home_run_multiplier' => env('MLB_OFFENSIVE_HR_MULT', 10),
        ],
        'pitching_rating' => [
            'era_scale' => env('MLB_PITCHING_ERA_SCALE', 10),
            'era_max' => env('MLB_PITCHING_ERA_MAX', 100),
        ],
        'defensive_rating' => [
            'fielding_pct_multiplier' => env('MLB_DEFENSIVE_FLD_MULT', 100),
            'errors_multiplier' => env('MLB_DEFENSIVE_ERR_MULT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prediction Configuration
    |--------------------------------------------------------------------------
    */
    'prediction' => [
        'use_previous_season_metrics_fallback' => true,
        'injury_out_spread_penalty' => 0.30,
        'injury_questionable_spread_penalty' => 0.10,
        'injury_out_total_penalty' => 0.15,
        'injury_questionable_total_penalty' => 0.05,
        'early_season' => [
            'ramp_games' => env('MLB_EARLY_SEASON_RAMP_GAMES', 20),
            'team_weight_start' => env('MLB_EARLY_SEASON_TEAM_WEIGHT_START', 0.45),
            'context_scale_min' => env('MLB_EARLY_SEASON_CONTEXT_SCALE_MIN', 0.35),
        ],
        'probable_pitcher_out_spread_penalty' => env('MLB_PROBABLE_PITCHER_OUT_SPREAD_PENALTY', 1.1),
        'probable_pitcher_questionable_spread_penalty' => env('MLB_PROBABLE_PITCHER_QUESTIONABLE_SPREAD_PENALTY', 0.45),
        'probable_pitcher_out_total_boost' => env('MLB_PROBABLE_PITCHER_OUT_TOTAL_BOOST', 0.7),
        'probable_pitcher_questionable_total_boost' => env('MLB_PROBABLE_PITCHER_QUESTIONABLE_TOTAL_BOOST', 0.25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Playoff Forecast Configuration
    |--------------------------------------------------------------------------
    |
    | Lightweight projection settings for MLB postseason futures.
    |
    */

    'playoff_forecast' => [
        'simulations' => 1,
        'playoff_spots_per_league' => 6,
        'bubble_steepness' => 1.1,
        'league_championship_base' => 0.44,
        'league_champ_seed_penalty' => 0.07,
        'regression' => [
            'enabled' => true,
            'metric_factor' => 0.45,
            'win_pct_factor' => 0.45,
            'sos_factor' => 0.35,
            'elo_factor' => env('MLB_OFFSEASON_ELO_REGRESSION', 0.33),
        ],
        'selection_weights' => [
            'offensive_rating' => 0.22,
            'pitching_rating' => 0.22,
            'defensive_rating' => 0.14,
            'elo_rating' => 0.18,
            'win_pct' => 0.18,
            'strength_of_schedule' => 0.06,
        ],
    ],

];
