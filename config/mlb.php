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

    'bullpen_ratings' => [
        'lookback_games' => env('MLB_BULLPEN_RATINGS_LOOKBACK_GAMES', 12),
        'recency_decay' => env('MLB_BULLPEN_RATINGS_RECENCY_DECAY', 0.82),
        'baseline_rating' => env('MLB_BULLPEN_RATINGS_BASELINE', 100.0),
        'min_rating' => env('MLB_BULLPEN_RATINGS_MIN', 60.0),
        'max_rating' => env('MLB_BULLPEN_RATINGS_MAX', 135.0),
        'baselines' => [
            'era' => env('MLB_BULLPEN_BASELINE_ERA', 4.10),
            'whip' => env('MLB_BULLPEN_BASELINE_WHIP', 1.28),
            'k_per_nine' => env('MLB_BULLPEN_BASELINE_K9', 8.8),
            'bb_per_nine' => env('MLB_BULLPEN_BASELINE_BB9', 3.5),
            'hr_per_nine' => env('MLB_BULLPEN_BASELINE_HR9', 1.1),
        ],
        'divisors' => [
            'era' => env('MLB_BULLPEN_ERA_DIVISOR', 0.45),
            'whip' => env('MLB_BULLPEN_WHIP_DIVISOR', 0.06),
            'k_per_nine' => env('MLB_BULLPEN_K9_DIVISOR', 0.8),
            'bb_per_nine' => env('MLB_BULLPEN_BB9_DIVISOR', 0.5),
            'hr_per_nine' => env('MLB_BULLPEN_HR9_DIVISOR', 0.25),
        ],
        'weights' => [
            'era' => env('MLB_BULLPEN_ERA_WEIGHT', 4.0),
            'whip' => env('MLB_BULLPEN_WHIP_WEIGHT', 3.4),
            'k_per_nine' => env('MLB_BULLPEN_K9_WEIGHT', 1.2),
            'bb_per_nine' => env('MLB_BULLPEN_BB9_WEIGHT', 1.2),
            'hr_per_nine' => env('MLB_BULLPEN_HR9_WEIGHT', 1.0),
            'recent_form' => env('MLB_BULLPEN_RECENT_FORM_WEIGHT', 1.5),
            'workload_penalty' => env('MLB_BULLPEN_WORKLOAD_WEIGHT', 1.2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prediction Configuration
    |--------------------------------------------------------------------------
    */
    'prediction' => [
        'use_previous_season_metrics_fallback' => true,
        'spread_to_probability_coefficient' => env('MLB_SPREAD_TO_PROBABILITY_COEFFICIENT', 7.0),
        'elo_diff_to_spread_divisor' => env('MLB_ELO_DIFF_TO_SPREAD_DIVISOR', 44.0),
        'total_model' => [
            'base_runs' => env('MLB_TOTAL_MODEL_BASE_RUNS', 9.7),
            'average_elo_baseline' => env('MLB_TOTAL_MODEL_AVERAGE_ELO_BASELINE', 1500.0),
            'average_elo_divisor' => env('MLB_TOTAL_MODEL_AVERAGE_ELO_DIVISOR', 80.0),
        ],
        'historical_priors' => [
            'enabled' => env('MLB_HISTORICAL_PRIORS_ENABLED', true),
            'lookback_seasons' => env('MLB_HISTORICAL_PRIORS_LOOKBACK_SEASONS', 2),
            'season_decay' => env('MLB_HISTORICAL_PRIORS_SEASON_DECAY', 0.65),
            'max_weight' => env('MLB_HISTORICAL_PRIORS_MAX_WEIGHT', 0.35),
            'spread_run_diff_multiplier' => env('MLB_HISTORICAL_PRIORS_SPREAD_RUN_DIFF_MULTIPLIER', 0.35),
            'total_run_environment_multiplier' => env('MLB_HISTORICAL_PRIORS_TOTAL_RUN_ENVIRONMENT_MULTIPLIER', 0.30),
            'max_spread_adjustment' => env('MLB_HISTORICAL_PRIORS_MAX_SPREAD_ADJUSTMENT', 0.8),
            'max_total_adjustment' => env('MLB_HISTORICAL_PRIORS_MAX_TOTAL_ADJUSTMENT', 0.9),
        ],
        'situational' => [
            'bullpen' => [
                'lookback_games' => env('MLB_BULLPEN_FATIGUE_LOOKBACK_GAMES', 3),
                'spread_weight' => env('MLB_BULLPEN_FATIGUE_SPREAD_WEIGHT', 0.30),
                'total_weight' => env('MLB_BULLPEN_FATIGUE_TOTAL_WEIGHT', 0.22),
            ],
            'bullpen_quality' => [
                'enabled' => env('MLB_BULLPEN_QUALITY_ENABLED', true),
                'spread_weight' => env('MLB_BULLPEN_QUALITY_SPREAD_WEIGHT', 0.24),
                'total_weight' => env('MLB_BULLPEN_QUALITY_TOTAL_WEIGHT', 0.14),
                'score_divisor' => env('MLB_BULLPEN_QUALITY_SCORE_DIVISOR', 18.0),
            ],
            'handedness' => [
                'spread_weight' => env('MLB_HANDEDNESS_SPREAD_WEIGHT', 0.45),
                'total_weight' => env('MLB_HANDEDNESS_TOTAL_WEIGHT', 0.16),
            ],
            'advanced_ratings' => [
                'enabled' => env('MLB_ADVANCED_RATINGS_ENABLED', true),
                'spread_weight' => env('MLB_ADVANCED_RATINGS_SPREAD_WEIGHT', 0.18),
                'total_weight' => env('MLB_ADVANCED_RATINGS_TOTAL_WEIGHT', 0.16),
                'max_spread_adjustment' => env('MLB_ADVANCED_RATINGS_MAX_SPREAD_ADJUSTMENT', 0.6),
                'max_total_adjustment' => env('MLB_ADVANCED_RATINGS_MAX_TOTAL_ADJUSTMENT', 0.7),
                'baseline_ops' => env('MLB_ADVANCED_RATINGS_BASELINE_OPS', 0.720),
                'ops_divisor' => env('MLB_ADVANCED_RATINGS_OPS_DIVISOR', 0.080),
                'baseline_whip' => env('MLB_ADVANCED_RATINGS_BASELINE_WHIP', 1.280),
                'whip_divisor' => env('MLB_ADVANCED_RATINGS_WHIP_DIVISOR', 0.180),
                'baseline_team_era' => env('MLB_ADVANCED_RATINGS_BASELINE_TEAM_ERA', 4.20),
                'era_divisor' => env('MLB_ADVANCED_RATINGS_ERA_DIVISOR', 1.20),
            ],
            'starter_form' => [
                'enabled' => env('MLB_STARTER_FORM_ENABLED', true),
                'lookback_starts' => env('MLB_STARTER_FORM_LOOKBACK_STARTS', 4),
                'trend_divisor' => env('MLB_STARTER_FORM_TREND_DIVISOR', 60.0),
                'spread_weight' => env('MLB_STARTER_FORM_SPREAD_WEIGHT', 0.25),
                'total_weight' => env('MLB_STARTER_FORM_TOTAL_WEIGHT', 0.10),
            ],
        ],
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
        'depth_chart' => [
            'starter_multiplier' => 1.20,
            'rotation_multiplier' => 1.05,
            'pitcher_multiplier' => 1.60,
        ],
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
