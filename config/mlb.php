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
        'default' => env('MLB_DEFAULT_SEASON', (int) date('Y')),
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
        'default_team_metrics_type' => 2,
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
        'alignment' => [
            'BAL' => ['league' => 'American League', 'division' => 'East'],
            'BOS' => ['league' => 'American League', 'division' => 'East'],
            'NYY' => ['league' => 'American League', 'division' => 'East'],
            'TB' => ['league' => 'American League', 'division' => 'East'],
            'TOR' => ['league' => 'American League', 'division' => 'East'],
            'CWS' => ['league' => 'American League', 'division' => 'Central'],
            'CHW' => ['league' => 'American League', 'division' => 'Central'],
            'CLE' => ['league' => 'American League', 'division' => 'Central'],
            'DET' => ['league' => 'American League', 'division' => 'Central'],
            'KC' => ['league' => 'American League', 'division' => 'Central'],
            'MIN' => ['league' => 'American League', 'division' => 'Central'],
            'HOU' => ['league' => 'American League', 'division' => 'West'],
            'LAA' => ['league' => 'American League', 'division' => 'West'],
            'ATH' => ['league' => 'American League', 'division' => 'West'],
            'OAK' => ['league' => 'American League', 'division' => 'West'],
            'SEA' => ['league' => 'American League', 'division' => 'West'],
            'TEX' => ['league' => 'American League', 'division' => 'West'],
            'ATL' => ['league' => 'National League', 'division' => 'East'],
            'MIA' => ['league' => 'National League', 'division' => 'East'],
            'NYM' => ['league' => 'National League', 'division' => 'East'],
            'PHI' => ['league' => 'National League', 'division' => 'East'],
            'WSH' => ['league' => 'National League', 'division' => 'East'],
            'CHC' => ['league' => 'National League', 'division' => 'Central'],
            'CIN' => ['league' => 'National League', 'division' => 'Central'],
            'MIL' => ['league' => 'National League', 'division' => 'Central'],
            'PIT' => ['league' => 'National League', 'division' => 'Central'],
            'STL' => ['league' => 'National League', 'division' => 'Central'],
            'ARI' => ['league' => 'National League', 'division' => 'West'],
            'COL' => ['league' => 'National League', 'division' => 'West'],
            'LAD' => ['league' => 'National League', 'division' => 'West'],
            'SD' => ['league' => 'National League', 'division' => 'West'],
            'SF' => ['league' => 'National League', 'division' => 'West'],
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
            'baseline' => env('MLB_OFFENSIVE_RATING_BASELINE', 100.0),
            'min' => env('MLB_OFFENSIVE_RATING_MIN', 50.0),
            'max' => env('MLB_OFFENSIVE_RATING_MAX', 180.0),
            'league_runs_per_game' => env('MLB_OFFENSIVE_LEAGUE_RPG', 4.40),
            'league_ops' => env('MLB_OFFENSIVE_LEAGUE_OPS', 0.720),
            'league_home_runs_per_game' => env('MLB_OFFENSIVE_LEAGUE_HRG', 1.10),
            'runs_per_game_weight' => env('MLB_OFFENSIVE_RPG_WEIGHT', 12.0),
            'ops_weight' => env('MLB_OFFENSIVE_OPS_WEIGHT', 120.0),
            'home_run_rate_weight' => env('MLB_OFFENSIVE_HRG_WEIGHT', 5.0),
        ],
        'pitching_rating' => [
            'baseline' => env('MLB_PITCHING_RATING_BASELINE', 100.0),
            'min' => env('MLB_PITCHING_RATING_MIN', 50.0),
            'max' => env('MLB_PITCHING_RATING_MAX', 180.0),
            'league_runs_allowed_per_game' => env('MLB_PITCHING_LEAGUE_RAG', 4.40),
            'league_era' => env('MLB_PITCHING_LEAGUE_ERA', 4.20),
            'league_whip' => env('MLB_PITCHING_LEAGUE_WHIP', 1.30),
            'league_k_minus_walks_per_game' => env('MLB_PITCHING_LEAGUE_K_BB_G', 5.20),
            'league_home_runs_allowed_per_game' => env('MLB_PITCHING_LEAGUE_HR_ALLOWED_G', 1.10),
            'runs_allowed_weight' => env('MLB_PITCHING_RA_WEIGHT', 8.0),
            'era_weight' => env('MLB_PITCHING_ERA_WEIGHT', 7.0),
            'whip_weight' => env('MLB_PITCHING_WHIP_WEIGHT', 35.0),
            'k_minus_walk_weight' => env('MLB_PITCHING_K_BB_WEIGHT', 3.0),
            'home_runs_allowed_weight' => env('MLB_PITCHING_HR_ALLOWED_WEIGHT', 4.0),
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
        /**
         * Home-field advantage applied to the predicted spread only (in Elo points).
         * Decoupled from `mlb.elo.home_field_advantage`, which governs Elo-update math.
         * Falls back to the Elo HFA if unset, preserving prior behavior.
         */
        'home_field_advantage' => env('MLB_PREDICTION_HOME_FIELD_ADVANTAGE', 5),
        'spread_to_probability_coefficient' => env('MLB_SPREAD_TO_PROBABILITY_COEFFICIENT', 10.0),
        'elo_diff_to_spread_divisor' => env('MLB_ELO_DIFF_TO_SPREAD_DIVISOR', 44.0),
        'total_model' => [
            'base_runs' => env('MLB_TOTAL_MODEL_BASE_RUNS', 10.6),
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

        /**
         * Per-venue total run adjustments derived from observed bias (shrunk toward 0 with k=20).
         * Positive = add runs (under-predicted park); negative = subtract (over-predicted park).
         * Keys match mlb_games.venue_name. Refresh by re-running the derivation script periodically.
         */
        'park_factors' => [
            'Angel Stadium' => -0.51,
            'Citi Field' => 0.24,
            'Citizens Bank Park' => 0.57,
            'Comerica Park' => 0.32,
            'Coors Field' => 0.90,
            'Daikin Park' => 0.67,
            'Dodger Stadium' => -0.76,
            'Fenway Park' => -0.87,
            'Globe Life Field' => -1.58,
            'Kauffman Stadium' => 0.53,
            'Nationals Park' => 1.48,
            'Oracle Park' => -0.36,
            'Oriole Park at Camden Yards' => 0.36,
            'Petco Park' => -0.30,
            'PNC Park' => 0.91,
            'Progressive Field' => -0.80,
            'Sutter Health Park' => 1.21,
            'T-Mobile Park' => -0.57,
            'Target Field' => 0.59,
            'Tropicana Field' => -0.33,
            'Yankee Stadium' => 0.93,
        ],

        'actual_weather' => [
            'enabled' => env('MLB_ACTUAL_WEATHER_ENABLED', true),
            'wind_under_threshold_mph' => env('MLB_ACTUAL_WEATHER_WIND_UNDER_THRESHOLD_MPH', 10),
            'gust_under_threshold_mph' => env('MLB_ACTUAL_WEATHER_GUST_UNDER_THRESHOLD_MPH', 18),
            'precip_under_threshold_inches' => env('MLB_ACTUAL_WEATHER_PRECIP_UNDER_THRESHOLD_INCHES', 0.02),
            'cold_under_threshold_f' => env('MLB_ACTUAL_WEATHER_COLD_UNDER_THRESHOLD_F', 50),
            'warm_over_threshold_f' => env('MLB_ACTUAL_WEATHER_WARM_OVER_THRESHOLD_F', 82),
            'humidity_over_threshold' => env('MLB_ACTUAL_WEATHER_HUMIDITY_OVER_THRESHOLD', 70),
            'wind_out_total_weight' => env('MLB_ACTUAL_WEATHER_WIND_OUT_TOTAL_WEIGHT', 0.055),
            'wind_in_total_weight' => env('MLB_ACTUAL_WEATHER_WIND_IN_TOTAL_WEIGHT', -0.065),
            'gust_out_total_weight' => env('MLB_ACTUAL_WEATHER_GUST_OUT_TOTAL_WEIGHT', 0.02),
            'gust_in_total_weight' => env('MLB_ACTUAL_WEATHER_GUST_IN_TOTAL_WEIGHT', -0.025),
            'precip_total_adjustment' => env('MLB_ACTUAL_WEATHER_PRECIP_TOTAL_ADJUSTMENT', -0.35),
            'cold_total_adjustment' => env('MLB_ACTUAL_WEATHER_COLD_TOTAL_ADJUSTMENT', -0.30),
            'warm_total_adjustment' => env('MLB_ACTUAL_WEATHER_WARM_TOTAL_ADJUSTMENT', 0.20),
            'humidity_total_adjustment' => env('MLB_ACTUAL_WEATHER_HUMIDITY_TOTAL_ADJUSTMENT', 0.10),
            'max_total_adjustment' => env('MLB_ACTUAL_WEATHER_MAX_TOTAL_ADJUSTMENT', 1.6),
            'wind_direction_tolerance_degrees' => env('MLB_ACTUAL_WEATHER_WIND_DIRECTION_TOLERANCE_DEGREES', 45),
            'indoor_venue_keywords' => ['tropicana field'],
            'retractable_roof_venue_keywords' => [
                'american family field',
                'chase field',
                'daikin park',
                'globe life field',
                'loanDepot park',
                'loanDepot Park',
                'marlins park',
                'rogers centre',
                'safeco field',
                't-mobile park',
            ],
            'venue_coordinates' => [
                'Angel Stadium' => ['latitude' => 33.8003, 'longitude' => -117.8827],
                'American Family Field' => ['latitude' => 43.0280, 'longitude' => -87.9712],
                'Busch Stadium' => ['latitude' => 38.6226, 'longitude' => -90.1928],
                'Chase Field' => ['latitude' => 33.4455, 'longitude' => -112.0667],
                'Citi Field' => ['latitude' => 40.7571, 'longitude' => -73.8458],
                'Citizens Bank Park' => ['latitude' => 39.9061, 'longitude' => -75.1665],
                'Comerica Park' => ['latitude' => 42.3390, 'longitude' => -83.0485],
                'Coors Field' => ['latitude' => 39.7562, 'longitude' => -104.9942],
                'Daikin Park' => ['latitude' => 29.7573, 'longitude' => -95.3555],
                'Dodger Stadium' => ['latitude' => 34.0739, 'longitude' => -118.2400],
                'Fenway Park' => ['latitude' => 42.3467, 'longitude' => -71.0972],
                'Globe Life Field' => ['latitude' => 32.7473, 'longitude' => -97.0842],
                'Great American Ball Park' => ['latitude' => 39.0979, 'longitude' => -84.5082],
                'Kauffman Stadium' => ['latitude' => 39.0517, 'longitude' => -94.4803],
                'Las Vegas Ballpark' => ['latitude' => 36.1595, 'longitude' => -115.3300],
                'loanDepot park' => ['latitude' => 25.7781, 'longitude' => -80.2197],
                'Nationals Park' => ['latitude' => 38.8730, 'longitude' => -77.0074],
                'Oracle Park' => ['latitude' => 37.7786, 'longitude' => -122.3893],
                'Oriole Park at Camden Yards' => ['latitude' => 39.2839, 'longitude' => -76.6217],
                'Petco Park' => ['latitude' => 32.7073, 'longitude' => -117.1566],
                'PNC Park' => ['latitude' => 40.4469, 'longitude' => -80.0057],
                'Progressive Field' => ['latitude' => 41.4962, 'longitude' => -81.6852],
                'Rate Field' => ['latitude' => 41.8300, 'longitude' => -87.6338],
                'Rogers Centre' => ['latitude' => 43.6414, 'longitude' => -79.3894],
                'Sutter Health Park' => ['latitude' => 38.5804, 'longitude' => -121.5139],
                'T-Mobile Park' => ['latitude' => 47.5914, 'longitude' => -122.3325],
                'Target Field' => ['latitude' => 44.9817, 'longitude' => -93.2776],
                'Tropicana Field' => ['latitude' => 27.7683, 'longitude' => -82.6534],
                'Truist Park' => ['latitude' => 33.8907, 'longitude' => -84.4677],
                'Wrigley Field' => ['latitude' => 41.9484, 'longitude' => -87.6553],
                'Yankee Stadium' => ['latitude' => 40.8296, 'longitude' => -73.9262],
            ],
            'wind_out_to_center_degrees' => [
                // Degrees are ballpark-specific bearings from home plate toward center field.
                // Add or tune venues as we validate total performance against historical weather.
                'Coors Field' => 20,
                'Fenway Park' => 45,
                'Oracle Park' => 80,
                'Wrigley Field' => 45,
                'Yankee Stadium' => 75,
            ],
        ],
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
        'simulations' => env('MLB_PLAYOFF_FORECAST_SIMULATIONS', 5000),
        'playoff_spots_per_league' => 6,
        'simulation_regular_season_noise' => env('MLB_PLAYOFF_FORECAST_REGULAR_SEASON_NOISE', 0.55),
        'simulation_matchup_scale' => env('MLB_PLAYOFF_FORECAST_MATCHUP_SCALE', 0.85),
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

    /*
    |--------------------------------------------------------------------------
    | Betting Signal Configuration
    |--------------------------------------------------------------------------
    |
    | Thresholds for surfacing MLB futures, slate, totals, and streak signals.
    |
    */

    'signals' => [
        'run_line_min_edge' => env('MLB_SIGNALS_RUN_LINE_MIN_EDGE', 0.75),
        'total_min_edge' => env('MLB_SIGNALS_TOTAL_MIN_EDGE', 0.75),
        'min_streak_length' => env('MLB_SIGNALS_MIN_STREAK_LENGTH', 4),
        'odds_stale_hours' => env('MLB_SIGNALS_ODDS_STALE_HOURS', 12),
        'live_stale_minutes' => env('MLB_SIGNALS_LIVE_STALE_MINUTES', 6),
        'bet_filter' => [
            'moneyline_enabled' => env('MLB_BET_FILTER_MONEYLINE_ENABLED', true),
            'run_line_enabled' => env('MLB_BET_FILTER_RUN_LINE_ENABLED', false),
            'total_enabled' => env('MLB_BET_FILTER_TOTAL_ENABLED', false),
            'strong_min_score' => env('MLB_BET_FILTER_STRONG_MIN_SCORE', 70),
            'lean_min_score' => env('MLB_BET_FILTER_LEAN_MIN_SCORE', 55),
            'min_confidence' => env('MLB_BET_FILTER_MIN_CONFIDENCE', 55),
            'strong_confidence' => env('MLB_BET_FILTER_STRONG_CONFIDENCE', 60),
            'min_model_spread' => env('MLB_BET_FILTER_MIN_MODEL_SPREAD', 1.0),
            'strong_model_spread' => env('MLB_BET_FILTER_STRONG_MODEL_SPREAD', 1.5),
            'min_run_line_edge' => env('MLB_BET_FILTER_MIN_RUN_LINE_EDGE', 1.0),
            'min_total_edge' => env('MLB_BET_FILTER_MIN_TOTAL_EDGE', 1.25),
            'max_recommendations' => env('MLB_BET_FILTER_MAX_RECOMMENDATIONS', 8),
        ],
    ],

];
