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
    | NFL season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('NFL_DEFAULT_SEASON', 2026),
        'types' => [
            'preseason' => 1,
            'regular' => 2,
            'postseason' => 3,
        ],
        'weeks' => [
            'preseason' => 4,
            'regular' => 18,
            'postseason' => 5,
        ],
        'analytics_types' => [2, 3],
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
            'requests_per_minute' => env('NFL_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('NFL_API_DELAY_MS', 100),
        ],
        'timeout' => env('NFL_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('NFL_API_RETRY_ENABLED', true),
            'max_attempts' => env('NFL_API_RETRY_ATTEMPTS', 3),
            'delay' => env('NFL_API_RETRY_DELAY', 1000),
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
        'queue' => env('NFL_SYNC_QUEUE', 'default'),
        'batch_size' => env('NFL_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('NFL_SYNC_JOB_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to NFL teams.
    |
    */

    'teams' => [
        'count' => 32,
        'conferences' => [
            'afc' => 'AFC',
            'nfc' => 'NFC',
        ],
        'divisions_per_conference' => 4,
        'teams_per_division' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | ELO Rating System Configuration
    |--------------------------------------------------------------------------
    |
    | These parameters control the ELO rating calculation for NFL teams.
    | Values have been calibrated against historical game data.
    |
    */

    'elo' => [
        /**
         * Default starting ELO rating for all teams
         */
        'default_rating' => 1500,

        /**
         * Fraction of each team's prior-season ending Elo that regresses toward
         * the league mean during the offseason. 0.33 matches FiveThirtyEight's
         * published NFL Elo model.
         */
        'offseason_regression_factor' => 0.33,

        /**
         * Base K-factor for regular season games
         * Controls how much ratings change after each game
         */
        'base_k_factor' => 16,

        /**
         * Home field advantage in ELO points
         * Calibrated from 2025 season data
         */
        'home_field_advantage' => 25,

        /**
         * K-factor multiplier for playoff games
         * Playoff games have higher impact on ratings
         */
        'playoff_multiplier' => 1.5,

        /**
         * K-factor multiplier for early season games (weeks 1-4)
         * Higher volatility as teams establish identity
         */
        'recency_multiplier' => 1.1,

        /**
         * Early season weeks threshold for recency multiplier
         */
        'recency_weeks' => 4,

        /**
         * Margin of victory coefficient
         * Used in logarithmic MOV formula: 1.0 + (log(margin + 1) * coefficient)
         */
        'mov_coefficient' => 0.25,

        /**
         * Maximum margin of victory multiplier
         * Prevents blowouts from dominating too much
         */
        'max_mov_multiplier' => 2.2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prediction Configuration
    |--------------------------------------------------------------------------
    |
    | Parameters for generating game predictions and spreads.
    |
    */

    'predictions' => [
        /**
         * ELO points per predicted point spread
         * Calibrated to minimize spread prediction error
         */
        'points_per_elo' => 0.09,

        /**
         * Maximum predicted spread (points)
         * NFL spreads rarely exceed ±15 points
         */
        'max_spread' => 15,

        /**
         * Minimum predicted spread (points)
         */
        'min_spread' => -15,

        /**
         * Average NFL game total (combined score)
         * Used as baseline for over/under predictions
         */
        'average_total' => env('NFL_AVERAGE_TOTAL', 46.5),

        /**
         * Injury adjustments
         */
        'injury_out_spread_penalty' => 0.50,
        'injury_questionable_spread_penalty' => 0.20,
        'injury_out_total_penalty' => 0.30,
        'injury_questionable_total_penalty' => 0.10,
        'depth_chart' => [
            'starter_multiplier' => 1.35,
            'rotation_multiplier' => 1.10,
            'qb_multiplier' => 2.40,
            'skill_multiplier' => 1.45,
            'role_multipliers' => [
                'QB' => env('NFL_DEPTH_CHART_ROLE_QB_MULTIPLIER', 2.80),
                'LT' => env('NFL_DEPTH_CHART_ROLE_LT_MULTIPLIER', 1.95),
                'RT' => env('NFL_DEPTH_CHART_ROLE_RT_MULTIPLIER', 1.75),
                'C' => env('NFL_DEPTH_CHART_ROLE_C_MULTIPLIER', 1.55),
                'WR1' => env('NFL_DEPTH_CHART_ROLE_WR1_MULTIPLIER', 1.75),
                'WR' => env('NFL_DEPTH_CHART_ROLE_WR_MULTIPLIER', 1.45),
                'RB1' => env('NFL_DEPTH_CHART_ROLE_RB1_MULTIPLIER', 1.50),
                'TE1' => env('NFL_DEPTH_CHART_ROLE_TE1_MULTIPLIER', 1.40),
                'EDGE1' => env('NFL_DEPTH_CHART_ROLE_EDGE1_MULTIPLIER', 1.75),
                'CB1' => env('NFL_DEPTH_CHART_ROLE_CB1_MULTIPLIER', 1.70),
                'S' => env('NFL_DEPTH_CHART_ROLE_S_MULTIPLIER', 1.35),
                'K' => env('NFL_DEPTH_CHART_ROLE_K_MULTIPLIER', 1.20),
            ],
            'win_probability_adjustment_per_point' => 0.03,
        ],

        /*
        |--------------------------------------------------------------------------
        | True EPA Guarded Rollout
        |--------------------------------------------------------------------------
        |
        | Optional blend of team true EPA/play into pregame predictions.
        | Set enabled=false for instant rollback to legacy Elo-only model.
        |
        */
        'true_epa' => [
            'enabled' => env('NFL_TRUE_EPA_ENABLED', true),
            'blend_weight' => env('NFL_TRUE_EPA_BLEND_WEIGHT', 0.35),
            'spread_points_per_epa' => env('NFL_TRUE_EPA_SPREAD_POINTS_PER_EPA', 14.0),
            'win_prob_max_adjustment' => env('NFL_TRUE_EPA_WIN_PROB_MAX_ADJ', 0.12),
            'win_prob_sensitivity' => env('NFL_TRUE_EPA_WIN_PROB_SENS', 8.0),
            'total_points_per_epa_component' => env('NFL_TRUE_EPA_TOTAL_POINTS_PER_COMP', 20.0),
            'min_predicted_total' => env('NFL_TRUE_EPA_MIN_TOTAL', 28.0),
            'max_predicted_total' => env('NFL_TRUE_EPA_MAX_TOTAL', 66.0),
        ],

        /*
        |--------------------------------------------------------------------------
        | nflverse Context Fallbacks
        |--------------------------------------------------------------------------
        |
        | These fill preseason/upcoming-board gaps when first-party team metrics,
        | ESPN QB context, or active injury rows are not available yet.
        |
        */
        'nflverse' => [
            'true_epa_fallback' => [
                'enabled' => env('NFL_NFLVERSE_TRUE_EPA_FALLBACK_ENABLED', true),
                'min_games' => env('NFL_NFLVERSE_TRUE_EPA_FALLBACK_MIN_GAMES', 4),
                'min_plays' => env('NFL_NFLVERSE_TRUE_EPA_FALLBACK_MIN_PLAYS', 200),
                'blend_weight' => env('NFL_NFLVERSE_TRUE_EPA_FALLBACK_BLEND_WEIGHT', env('NFL_TRUE_EPA_BLEND_WEIGHT', 0.35)),
            ],
            'qb_depth_fallback' => [
                'enabled' => env('NFL_NFLVERSE_QB_DEPTH_FALLBACK_ENABLED', true),
            ],
            'qb_weekly_stats_fallback' => [
                'enabled' => env('NFL_NFLVERSE_QB_WEEKLY_STATS_FALLBACK_ENABLED', true),
            ],
            'injury_fallback' => [
                'enabled' => env('NFL_NFLVERSE_INJURY_FALLBACK_ENABLED', true),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Preseason Signal Blend
        |--------------------------------------------------------------------------
        |
        | Used when in-season EPA isn't available yet (typical for preseason and
        | Week 1). Blends the Elo-derived spread with a predictive_rating-derived
        | spread from the team metrics. predictive_rating already bakes in the
        | offseason adjustment (QB/skill continuity, injuries) computed by
        | HistoricalTeamMetricCalculator.
        |
        */
        'preseason_signal' => [
            'enabled' => env('NFL_PRESEASON_SIGNAL_ENABLED', true),
            'blend_weight' => env('NFL_PRESEASON_SIGNAL_BLEND_WEIGHT', 0.25),
        ],

        /*
        |--------------------------------------------------------------------------
        | Market Blend
        |--------------------------------------------------------------------------
        |
        | When nfl_games.odds_data has spread/total markets from a bookmaker,
        | blend the model spread/total toward the market consensus. Higher weight
        | values trust the model more; 1.0 means model only (no market blend).
        |
        */
        'market_blend' => [
            'enabled' => env('NFL_MARKET_BLEND_ENABLED', true),
            'spread_model_weight' => env('NFL_MARKET_BLEND_SPREAD_WEIGHT', 0.5),
            'total_model_weight' => env('NFL_MARKET_BLEND_TOTAL_WEIGHT', 0.6),
        ],

        'spread_to_probability_coefficient' => env('NFL_SPREAD_TO_PROBABILITY_COEFFICIENT', 7.0),

        'injury_scope' => [
            'unknown_return_days' => env('NFL_INJURY_UNKNOWN_RETURN_DAYS', 21),
        ],

        'depth_chart_injuries' => [
            'enabled' => env('NFL_DEPTH_CHART_INJURIES_ENABLED', true),
        ],

        'rolling_efficiency' => [
            'enabled' => env('NFL_ROLLING_EFFICIENCY_ENABLED', false),
            'min_games' => env('NFL_ROLLING_EFFICIENCY_MIN_GAMES', 2),
            'blend_weight' => env('NFL_ROLLING_EFFICIENCY_BLEND_WEIGHT', 0.35),
            'margin_weight' => env('NFL_ROLLING_EFFICIENCY_MARGIN_WEIGHT', 0.55),
            'recent_margin_weight' => env('NFL_ROLLING_EFFICIENCY_RECENT_MARGIN_WEIGHT', 0.25),
            'yard_diff_weight' => env('NFL_ROLLING_EFFICIENCY_YARD_DIFF_WEIGHT', 0.12),
            'turnover_weight' => env('NFL_ROLLING_EFFICIENCY_TURNOVER_WEIGHT', 0.75),
            'max_signal_spread' => env('NFL_ROLLING_EFFICIENCY_MAX_SIGNAL_SPREAD', 14.0),
            'recent_games' => env('NFL_ROLLING_EFFICIENCY_RECENT_GAMES', 5),
        ],

        'opponent_adjusted_efficiency' => [
            'enabled' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_ENABLED', false),
            'min_games' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_MIN_GAMES', 3),
            'blend_weight' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_BLEND_WEIGHT', 0.18),
            'margin_weight' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_MARGIN_WEIGHT', 0.45),
            'yard_weight' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_YARD_WEIGHT', 0.08),
            'red_zone_weight' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_RED_ZONE_WEIGHT', 2.0),
            'third_down_weight' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_THIRD_DOWN_WEIGHT', 2.0),
            'opponent_elo_weight' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_OPP_ELO_WEIGHT', 0.015),
            'max_signal_spread' => env('NFL_OPPONENT_ADJUSTED_EFFICIENCY_MAX_SIGNAL_SPREAD', 5.0),
        ],

        'total_environment' => [
            'enabled' => env('NFL_TOTAL_ENVIRONMENT_ENABLED', true),
            'min_games' => env('NFL_TOTAL_ENVIRONMENT_MIN_GAMES', 2),
            'recent_games' => env('NFL_TOTAL_ENVIRONMENT_RECENT_GAMES', 8),
            'blend_weight' => env('NFL_TOTAL_ENVIRONMENT_BLEND_WEIGHT', 0.35),
            'max_adjustment' => env('NFL_TOTAL_ENVIRONMENT_MAX_ADJUSTMENT', 4.0),
            'scoring_weight' => env('NFL_TOTAL_ENVIRONMENT_SCORING_WEIGHT', 0.22),
            'pace_weight' => env('NFL_TOTAL_ENVIRONMENT_PACE_WEIGHT', 0.11),
            'explosive_weight' => env('NFL_TOTAL_ENVIRONMENT_EXPLOSIVE_WEIGHT', 3.8),
            'red_zone_weight' => env('NFL_TOTAL_ENVIRONMENT_RED_ZONE_WEIGHT', 4.5),
            'third_down_weight' => env('NFL_TOTAL_ENVIRONMENT_THIRD_DOWN_WEIGHT', 2.5),
            'turnover_weight' => env('NFL_TOTAL_ENVIRONMENT_TURNOVER_WEIGHT', -34.0),
            'penalty_weight' => env('NFL_TOTAL_ENVIRONMENT_PENALTY_WEIGHT', -0.018),
            'league_combined_plays' => env('NFL_TOTAL_ENVIRONMENT_LEAGUE_COMBINED_PLAYS', 128.0),
            'league_yards_per_play' => env('NFL_TOTAL_ENVIRONMENT_LEAGUE_YARDS_PER_PLAY', 5.35),
            'league_red_zone_rate' => env('NFL_TOTAL_ENVIRONMENT_LEAGUE_RED_ZONE_RATE', 0.58),
            'league_third_down_rate' => env('NFL_TOTAL_ENVIRONMENT_LEAGUE_THIRD_DOWN_RATE', 0.39),
            'league_turnover_rate' => env('NFL_TOTAL_ENVIRONMENT_LEAGUE_TURNOVER_RATE', 0.020),
            'league_penalty_yards' => env('NFL_TOTAL_ENVIRONMENT_LEAGUE_PENALTY_YARDS', 48.0),
        ],

        'qb_form' => [
            'enabled' => env('NFL_QB_FORM_ENABLED', true),
            'min_prior_attempts' => env('NFL_QB_FORM_MIN_PRIOR_ATTEMPTS', 30),
            'blend_weight' => env('NFL_QB_FORM_BLEND_WEIGHT', 0.22),
            'max_signal_spread' => env('NFL_QB_FORM_MAX_SIGNAL_SPREAD', 6.0),
            'max_qb_score' => env('NFL_QB_FORM_MAX_QB_SCORE', 4.0),
            'full_weight_attempts' => env('NFL_QB_FORM_FULL_WEIGHT_ATTEMPTS', 30),
            'full_weight_games' => env('NFL_QB_FORM_FULL_WEIGHT_GAMES', 1),
            'early_season_week' => env('NFL_QB_FORM_EARLY_SEASON_WEEK', 4),
            'early_season_weight' => env('NFL_QB_FORM_EARLY_SEASON_WEIGHT', 1.0),
            'baseline_yards_per_attempt' => env('NFL_QB_FORM_BASELINE_YPA', 6.9),
            'baseline_td_rate' => env('NFL_QB_FORM_BASELINE_TD_RATE', 0.045),
            'baseline_int_rate' => env('NFL_QB_FORM_BASELINE_INT_RATE', 0.025),
            'baseline_sack_rate' => env('NFL_QB_FORM_BASELINE_SACK_RATE', 0.065),
            'ypa_weight' => env('NFL_QB_FORM_YPA_WEIGHT', 1.2),
            'td_rate_weight' => env('NFL_QB_FORM_TD_RATE_WEIGHT', 28.0),
            'int_rate_weight' => env('NFL_QB_FORM_INT_RATE_WEIGHT', 35.0),
            'sack_rate_weight' => env('NFL_QB_FORM_SACK_RATE_WEIGHT', 18.0),
            'rush_yards_weight' => env('NFL_QB_FORM_RUSH_YARDS_WEIGHT', 0.03),
            'experience_weight' => env('NFL_QB_FORM_EXPERIENCE_WEIGHT', 0.35),
        ],

        'player_position_grades' => [
            'enabled' => env('NFL_PLAYER_POSITION_GRADES_ENABLED', true),
            'min_coverage_rate' => env('NFL_PLAYER_POSITION_GRADES_MIN_COVERAGE', 0.25),
            'edge_threshold' => env('NFL_PLAYER_POSITION_GRADES_EDGE_THRESHOLD', 3.0),
        ],

        'line_matchup' => [
            'enabled' => env('NFL_LINE_MATCHUP_ENABLED', true),
            'min_games' => env('NFL_LINE_MATCHUP_MIN_GAMES', 2),
            'blend_weight' => env('NFL_LINE_MATCHUP_BLEND_WEIGHT', 0.18),
            'run_edge_weight' => env('NFL_LINE_MATCHUP_RUN_EDGE_WEIGHT', 1.35),
            'pressure_edge_weight' => env('NFL_LINE_MATCHUP_PRESSURE_EDGE_WEIGHT', 34.0),
            'max_signal_spread' => env('NFL_LINE_MATCHUP_MAX_SIGNAL_SPREAD', 4.0),
            'total_run_edge_weight' => env('NFL_LINE_MATCHUP_TOTAL_RUN_EDGE_WEIGHT', 0.8),
            'total_pressure_edge_weight' => env('NFL_LINE_MATCHUP_TOTAL_PRESSURE_EDGE_WEIGHT', 14.0),
            'max_total_adjustment' => env('NFL_LINE_MATCHUP_MAX_TOTAL_ADJUSTMENT', 3.0),
        ],

        'contextual_factors' => [
            'enabled' => env('NFL_CONTEXTUAL_FACTORS_ENABLED', true),
            'home_away_min_games' => env('NFL_CONTEXT_HOME_AWAY_MIN_GAMES', 2),
            'home_away_weight' => env('NFL_CONTEXT_HOME_AWAY_WEIGHT', 0.06),
            'division_lookback_games' => env('NFL_CONTEXT_DIVISION_LOOKBACK_GAMES', 6),
            'division_h2h_weight' => env('NFL_CONTEXT_DIVISION_H2H_WEIGHT', 0.05),
            'division_total_penalty' => env('NFL_CONTEXT_DIVISION_TOTAL_PENALTY', -0.4),
            'matchup_record_lookback_games' => env('NFL_CONTEXT_MATCHUP_RECORD_LOOKBACK_GAMES', 8),
            'matchup_record_h2h_weight' => env('NFL_CONTEXT_MATCHUP_RECORD_H2H_WEIGHT', 0.40),
            'matchup_record_division_weight' => env('NFL_CONTEXT_MATCHUP_RECORD_DIVISION_WEIGHT', 0.30),
            'matchup_record_conference_weight' => env('NFL_CONTEXT_MATCHUP_RECORD_CONFERENCE_WEIGHT', 0.20),
            'same_week_record_lookback_seasons' => env('NFL_CONTEXT_SAME_WEEK_RECORD_LOOKBACK_SEASONS', 10),
            'same_week_record_h2h_weight' => env('NFL_CONTEXT_SAME_WEEK_RECORD_H2H_WEIGHT', 0.25),
            'same_week_record_division_weight' => env('NFL_CONTEXT_SAME_WEEK_RECORD_DIVISION_WEIGHT', 0.18),
            'same_week_record_conference_weight' => env('NFL_CONTEXT_SAME_WEEK_RECORD_CONFERENCE_WEIGHT', 0.12),
            'cold_weather_total_adjustment' => env('NFL_CONTEXT_COLD_WEATHER_TOTAL_ADJUSTMENT', -0.6),
            'hot_weather_total_adjustment' => env('NFL_CONTEXT_HOT_WEATHER_TOTAL_ADJUSTMENT', -0.2),
            'rest_diff_weight' => env('NFL_CONTEXT_REST_DIFF_WEIGHT', 0.09),
            'short_rest_penalty' => env('NFL_CONTEXT_SHORT_REST_PENALTY', -0.25),
            'short_rest_total_penalty' => env('NFL_CONTEXT_SHORT_REST_TOTAL_PENALTY', -0.2),
            'consecutive_road_penalty' => env('NFL_CONTEXT_CONSECUTIVE_ROAD_PENALTY', -0.2),
            'coaching_weight' => env('NFL_CONTEXT_COACHING_WEIGHT', 0.12),
            'new_head_coach_weight' => env('NFL_CONTEXT_NEW_HEAD_COACH_WEIGHT', 0.20),
            'new_head_coach_uncertainty_weeks' => env('NFL_CONTEXT_NEW_HEAD_COACH_UNCERTAINTY_WEEKS', 4),
            'max_spread_adjustment' => env('NFL_CONTEXT_MAX_SPREAD_ADJUSTMENT', 2.0),
            'max_total_adjustment' => env('NFL_CONTEXT_MAX_TOTAL_ADJUSTMENT', 2.5),
            'coaching_priors' => [],
            'new_head_coaches' => [],
            'cold_weather_states' => ['NY', 'NJ', 'PA', 'OH', 'MI', 'WI', 'IL', 'MA', 'MD', 'CO', 'WA', 'MO', 'MN'],
            'hot_weather_states' => ['AZ', 'FL', 'TX', 'CA', 'NV'],
            'indoor_venue_keywords' => [
                'dome',
                'superdome',
                'ford field',
                'u.s. bank',
                'us bank',
                'allegiant',
                'sofi',
                'state farm stadium',
                'at&t stadium',
                'lucas oil',
                'mercedes-benz',
                'nrg stadium',
            ],
        ],

        'actual_weather' => [
            'enabled' => env('NFL_ACTUAL_WEATHER_ENABLED', true),
            'wind_under_threshold_mph' => env('NFL_ACTUAL_WEATHER_WIND_UNDER_THRESHOLD_MPH', 15),
            'gust_under_threshold_mph' => env('NFL_ACTUAL_WEATHER_GUST_UNDER_THRESHOLD_MPH', 24),
            'precip_under_threshold_inches' => env('NFL_ACTUAL_WEATHER_PRECIP_UNDER_THRESHOLD_INCHES', 0.03),
            'cold_under_threshold_f' => env('NFL_ACTUAL_WEATHER_COLD_UNDER_THRESHOLD_F', 32),
            'heat_under_threshold_f' => env('NFL_ACTUAL_WEATHER_HEAT_UNDER_THRESHOLD_F', 88),
            'wind_total_weight' => env('NFL_ACTUAL_WEATHER_WIND_TOTAL_WEIGHT', -0.08),
            'gust_total_weight' => env('NFL_ACTUAL_WEATHER_GUST_TOTAL_WEIGHT', -0.04),
            'precip_total_weight' => env('NFL_ACTUAL_WEATHER_PRECIP_TOTAL_WEIGHT', -18.0),
            'cold_total_adjustment' => env('NFL_ACTUAL_WEATHER_COLD_TOTAL_ADJUSTMENT', -1.0),
            'heat_total_adjustment' => env('NFL_ACTUAL_WEATHER_HEAT_TOTAL_ADJUSTMENT', -0.5),
            'max_total_adjustment' => env('NFL_ACTUAL_WEATHER_MAX_TOTAL_ADJUSTMENT', 4.0),
            'venue_coordinates' => [],
        ],

        'analysis_layer' => [
            'enabled' => env('NFL_ANALYSIS_LAYER_ENABLED', true),
            'low_confidence_threshold' => env('NFL_ANALYSIS_LOW_CONFIDENCE_THRESHOLD', 0.58),
            'min_spread_edge' => env('NFL_ANALYSIS_MIN_SPREAD_EDGE', 2.0),
            'min_total_edge' => env('NFL_ANALYSIS_MIN_TOTAL_EDGE', 3.0),
            'risk_flag_penalty' => env('NFL_ANALYSIS_RISK_FLAG_PENALTY', 4.0),
            'lean_model_signal_threshold' => env('NFL_ANALYSIS_LEAN_MODEL_SIGNAL_THRESHOLD', 55.0),
            'strong_model_signal_threshold' => env('NFL_ANALYSIS_STRONG_MODEL_SIGNAL_THRESHOLD', 65.0),
        ],

        'validated_signal_combos' => [
            [
                'name' => 'home_pass_protection_rest_spot',
                'label' => 'Home Pass Protection + Rest Spot',
                'market' => 'winner',
                'tier' => 'watchlist',
                'sample_size' => 25,
                'winner_hit_rate' => 88.0,
                'spread_mae' => 8.42,
                'codes' => ['home_pass_protection_edge', 'rest_travel_schedule_context'],
                'note' => 'High winner hit rate, smaller sample. Treat as a winner/watchlist signal before trusting ATS.',
            ],
            [
                'name' => 'qb_form_pressure_mismatch',
                'label' => 'QB Form + Pressure Mismatch',
                'market' => 'winner',
                'tier' => 'strong',
                'sample_size' => 127,
                'winner_hit_rate' => 73.2,
                'spread_mae' => 10.10,
                'codes' => ['qb_form_signal', 'weak_ol_vs_blitz_heavy_defense'],
                'note' => 'Large-sample winner signal. Spread precision still requires market edge confirmation.',
            ],
            [
                'name' => 'qb_form_matchup_pressure_mismatch',
                'label' => 'QB Form + Matchup History + Pressure',
                'market' => 'winner',
                'tier' => 'strong',
                'sample_size' => 123,
                'winner_hit_rate' => 74.0,
                'spread_mae' => 10.06,
                'codes' => ['qb_form_signal', 'recent_matchup_record_context', 'weak_ol_vs_blitz_heavy_defense'],
                'note' => 'Best larger-sample combo from current backtest set for winner direction.',
            ],
            [
                'name' => 'calibrated_qb_pressure_mismatch',
                'label' => 'Calibrated QB + Pressure Mismatch',
                'market' => 'winner',
                'tier' => 'strong',
                'sample_size' => 97,
                'winner_hit_rate' => 75.3,
                'spread_mae' => 10.74,
                'codes' => ['calibration_reduced_confidence', 'qb_form_signal', 'weak_ol_vs_blitz_heavy_defense'],
                'note' => 'Strong winner signal even after calibration reduced confidence; do not auto-upgrade spread.',
            ],
            [
                'name' => 'backup_qb_calibration_context',
                'label' => 'Backup QB + Calibration Context',
                'market' => 'winner',
                'tier' => 'watchlist',
                'sample_size' => 31,
                'winner_hit_rate' => 71.0,
                'spread_mae' => 9.36,
                'codes' => ['backup_qb_starting', 'calibration_reduced_confidence'],
                'note' => 'Useful flag for winner direction, but sample remains modest.',
            ],
        ],

        'bet_rules' => [
            'enabled' => env('NFL_BET_RULES_ENABLED', true),
            'rules' => [
                [
                    'name' => 'strong_qb_form_home_trench',
                    'action' => 'play',
                    'min_trust' => 65,
                    'require' => ['strong_model_signal', 'qb_form_home_edge', 'trench_matchup_home_edge'],
                    'avoid' => ['conflicting_signals', 'low_data_quality'],
                ],
                [
                    'name' => 'strong_qb_form_away_trench',
                    'action' => 'play',
                    'min_trust' => 65,
                    'require' => ['strong_model_signal', 'qb_form_away_edge', 'trench_matchup_away_edge'],
                    'avoid' => ['conflicting_signals', 'low_data_quality', 'road_team_travel_risk'],
                ],
                [
                    'name' => 'elite_qb_clean_pocket_mismatch',
                    'action' => 'play',
                    'min_trust' => 66,
                    'require' => ['strong_model_signal', 'elite_qb_vs_weak_secondary', 'ol_pass_protection_edge'],
                    'require_any' => ['explosive_pass_edge', 'passing_game_mismatch', 'qb_experience_edge'],
                    'avoid' => ['weather_increases_turnover_risk', 'conflicting_signals'],
                ],
                [
                    'name' => 'rookie_qb_pressure_fade',
                    'action' => 'play',
                    'min_trust' => 62,
                    'require' => ['rookie_qb_vs_pressure_defense', 'pressure_mismatch_against_qb'],
                    'require_any' => ['strong_model_signal', 'spread_market_edge', 'market_overreaction'],
                    'avoid' => ['conflicting_signals', 'low_data_quality'],
                    'market' => 'spread',
                ],
                [
                    'name' => 'home_rest_trench_confluence',
                    'action' => 'play',
                    'min_trust' => 64,
                    'require' => ['home_away_split_signal', 'extra_rest_edge', 'trench_matchup_home_edge'],
                    'require_any' => ['strong_model_signal', 'qb_form_home_edge', 'rolling_efficiency_home_edge'],
                    'avoid' => ['division_game_variance', 'conflicting_signals'],
                ],
                [
                    'name' => 'road_run_game_travels',
                    'action' => 'lean',
                    'min_trust' => 58,
                    'require' => ['away_run_game_edge', 'run_game_should_travel'],
                    'require_any' => ['trench_matchup_away_edge', 'rolling_efficiency_away_edge'],
                    'avoid' => ['road_team_travel_risk', 'cannot_run_block_risk'],
                ],
                [
                    'name' => 'division_dog_key_number',
                    'action' => 'lean',
                    'min_trust' => 55,
                    'require' => ['division_game_variance'],
                    'require_any' => ['key_number_edge_3', 'key_number_edge_7', 'spread_crosses_key_number'],
                    'avoid' => ['low_data_quality', 'injury_cluster_home', 'injury_cluster_away'],
                    'market' => 'spread',
                ],
                [
                    'name' => 'recent_matchup_record_confluence',
                    'action' => 'lean',
                    'min_trust' => 58,
                    'require_any' => ['recent_h2h_record_home_edge', 'recent_h2h_record_away_edge', 'recent_division_record_home_edge', 'recent_division_record_away_edge'],
                    'require' => ['multi_factor_confluence'],
                    'avoid' => ['low_data_quality', 'conflicting_signals'],
                ],
                [
                    'name' => 'conference_record_supports_strong_model',
                    'action' => 'lean',
                    'min_trust' => 62,
                    'require' => ['strong_model_signal'],
                    'require_any' => ['recent_conference_record_home_edge', 'recent_conference_record_away_edge'],
                    'avoid' => ['conflicting_signals'],
                ],
                [
                    'name' => 'same_week_record_supports_model',
                    'action' => 'lean',
                    'min_trust' => 58,
                    'require' => ['same_week_record_context'],
                    'require_any' => ['same_week_h2h_record_home_edge', 'same_week_h2h_record_away_edge', 'same_week_opponent_division_record_home_edge', 'same_week_opponent_division_record_away_edge', 'same_week_opponent_conference_record_home_edge', 'same_week_opponent_conference_record_away_edge'],
                    'avoid' => ['low_data_quality', 'conflicting_signals'],
                ],
                [
                    'name' => 'new_head_coach_context_watch',
                    'action' => 'lean',
                    'min_trust' => 58,
                    'require' => ['new_head_coach_context'],
                    'require_any' => ['new_head_coach_home_edge', 'new_head_coach_away_edge'],
                    'avoid' => ['low_data_quality', 'conflicting_signals', 'new_head_coach_uncertainty'],
                ],
                [
                    'name' => 'weather_total_under',
                    'action' => 'lean',
                    'min_trust' => 55,
                    'require_any' => ['wind_under_signal', 'rain_under_signal', 'cold_weather_under_signal', 'total_weather_suppression'],
                    'market' => 'total',
                ],
                [
                    'name' => 'weather_pace_under_confluence',
                    'action' => 'play',
                    'min_trust' => 60,
                    'require' => ['slow_pace_under_signal', 'total_weather_suppression'],
                    'require_any' => ['wind_under_signal', 'rain_under_signal', 'cold_weather_under_signal', 'run_heavy_clock_control'],
                    'avoid' => ['dome_scoring_boost', 'fast_pace_over_signal'],
                    'market' => 'total',
                ],
                [
                    'name' => 'dome_explosive_over_confluence',
                    'action' => 'lean',
                    'min_trust' => 58,
                    'require' => ['dome_scoring_boost', 'fast_pace_over_signal'],
                    'require_any' => ['explosive_pass_edge', 'explosive_offense_edge', 'poor_secondary_risk'],
                    'avoid' => ['slow_pace_under_signal', 'weather_increases_turnover_risk'],
                    'market' => 'total',
                ],
                [
                    'name' => 'market_key_number_confluence',
                    'action' => 'play',
                    'min_trust' => 65,
                    'require_any' => ['key_number_edge_3', 'key_number_edge_7', 'spread_crosses_key_number'],
                    'require' => ['bettable_confluence'],
                    'market' => 'spread',
                ],
                [
                    'name' => 'market_disagreement_with_model_quality',
                    'action' => 'play',
                    'min_trust' => 66,
                    'require' => ['model_market_disagreement', 'multi_factor_confluence'],
                    'require_any' => ['spread_market_edge', 'market_overreaction', 'sharp_line_move_signal'],
                    'avoid' => ['reverse_line_movement', 'stale_line_edge', 'conflicting_signals'],
                    'market' => 'spread',
                ],
                [
                    'name' => 'injury_cluster_total_suppression',
                    'action' => 'lean',
                    'min_trust' => 55,
                    'require_any' => ['key_offensive_weapon_out', 'wr_depth_risk', 'rb1_out_risk'],
                    'require' => ['slow_pace_under_signal'],
                    'avoid' => ['fast_pace_over_signal', 'dome_scoring_boost'],
                    'market' => 'total',
                ],
                [
                    'name' => 'defense_pressure_under',
                    'action' => 'lean',
                    'min_trust' => 57,
                    'require' => ['elite_defense_edge', 'pressure_mismatch_against_qb'],
                    'require_any' => ['qb_sack_pressure_risk', 'weak_ol_vs_blitz_heavy_defense', 'bend_dont_break_defense'],
                    'avoid' => ['fast_pace_over_signal'],
                    'market' => 'total',
                ],
                [
                    'name' => 'pass_conflicting_or_low_quality',
                    'action' => 'pass',
                    'require_any' => ['conflicting_signals', 'low_data_quality', 'small_sample_warning'],
                ],
                [
                    'name' => 'pass_high_edge_low_trust',
                    'action' => 'pass',
                    'require' => ['high_edge_low_trust'],
                ],
                [
                    'name' => 'pass_stale_or_reverse_market',
                    'action' => 'pass',
                    'require_any' => ['stale_line_edge', 'reverse_line_movement'],
                    'require' => ['market_overreaction'],
                ],
                [
                    'name' => 'pass_late_season_starter_uncertainty',
                    'action' => 'pass',
                    'require_any' => ['resting_starters_risk', 'late_season_motivation_risk'],
                    'require' => ['low_favorite_confidence'],
                ],
                [
                    'name' => 'pass_early_new_coach_low_confidence',
                    'action' => 'pass',
                    'require' => ['new_head_coach_uncertainty', 'low_favorite_confidence'],
                ],
            ],
        ],

        'adaptive_win_probability_calibration' => [
            'enabled' => env('NFL_ADAPTIVE_WIN_PROBABILITY_CALIBRATION_ENABLED', true),
            'lookback_games' => env('NFL_ADAPTIVE_WIN_PROBABILITY_LOOKBACK_GAMES', 512),
            'bucket_width' => env('NFL_ADAPTIVE_WIN_PROBABILITY_BUCKET_WIDTH', 0.05),
            'min_bucket_sample' => env('NFL_ADAPTIVE_WIN_PROBABILITY_MIN_BUCKET_SAMPLE', 30),
            'blend_weight' => env('NFL_ADAPTIVE_WIN_PROBABILITY_BLEND_WEIGHT', 0.45),
            'max_adjustment' => env('NFL_ADAPTIVE_WIN_PROBABILITY_MAX_ADJUSTMENT', 0.08),
            'min_favorite_probability' => env('NFL_ADAPTIVE_WIN_PROBABILITY_MIN_FAVORITE_PROBABILITY', 0.501),
            'coin_flip_tolerance' => env('NFL_ADAPTIVE_WIN_PROBABILITY_COIN_FLIP_TOLERANCE', 0.0005),
        ],

        'adaptive_point_calibration' => [
            'enabled' => env('NFL_ADAPTIVE_POINT_CALIBRATION_ENABLED', true),
            'lookback_games' => env('NFL_ADAPTIVE_POINT_CALIBRATION_LOOKBACK_GAMES', 384),
            'min_sample' => env('NFL_ADAPTIVE_POINT_CALIBRATION_MIN_SAMPLE', 48),
            'trim_fraction' => env('NFL_ADAPTIVE_POINT_CALIBRATION_TRIM_FRACTION', 0.10),
            'spread_blend_weight' => env('NFL_ADAPTIVE_POINT_CALIBRATION_SPREAD_BLEND_WEIGHT', 0.35),
            'total_blend_weight' => env('NFL_ADAPTIVE_POINT_CALIBRATION_TOTAL_BLEND_WEIGHT', 0.45),
            'max_spread_adjustment' => env('NFL_ADAPTIVE_POINT_CALIBRATION_MAX_SPREAD_ADJUSTMENT', 2.0),
            'max_total_adjustment' => env('NFL_ADAPTIVE_POINT_CALIBRATION_MAX_TOTAL_ADJUSTMENT', 2.5),
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
            'spread' => 2.5,      // Points
            'total' => 3.0,       // Points
            'moneyline' => 0.05,  // Probability (5%)
        ],

        // Kelly Criterion bet sizing
        'kelly' => [
            'fraction' => 0.25,   // Quarter Kelly (conservative)
            'max_percent' => 5.0, // Maximum recommended bet size
        ],

        'max_units' => 2.0,

        'key_numbers' => [3, 5, 7, 10],

        'risk' => [
            'early_season_weeks' => 2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Player Futures Projection Defaults
    |--------------------------------------------------------------------------
    |
    | Baselines for season-long player total projections. These are intentionally
    | conservative priors so the model does not overreact to a tiny early sample.
    |
    */
    'player_futures' => [
        'default_regular_season_games' => 18,
        'prior_games' => 4,
        'prior_share_games' => 6,
        'direct_rate_weight' => 0.45,
        'usage_share_weight' => 0.55,
        'schedule_adjustment_divisor' => 400.0,
        'min_schedule_adjustment' => 0.90,
        'max_schedule_adjustment' => 1.10,
        'teammate_injury_boost_per_weight' => 0.08,
        'max_teammate_injury_boost' => 0.18,
        'role_multipliers' => [
            'qb_starter' => 1.10,
            'qb_backup' => 0.82,
            'rb_lead' => 1.08,
            'rb_rotation' => 0.92,
            'wr_alpha' => 1.08,
            'wr_secondary' => 0.95,
            'te_primary' => 1.05,
            'te_secondary' => 0.93,
            'generic_starter' => 1.03,
            'generic_backup' => 0.90,
        ],
        'injury_availability' => [
            'out' => 0.0,
            'doubtful' => 0.2,
            'questionable' => 0.75,
            'day-to-day' => 0.85,
            'probable' => 0.95,
            'injured reserve' => 0.0,
            'ir' => 0.0,
            'suspended' => 0.0,
        ],
        'markets' => [
            'passing_yards' => [
                'label' => 'Passing Yards',
                'stat_field' => 'passing_yards',
                'positions' => ['QB'],
                'prior_per_game_by_position' => [
                    'QB' => 225.0,
                ],
                'prior_share_by_archetype' => [
                    'qb_starter' => 0.96,
                    'qb_backup' => 0.18,
                ],
                'default_stddev_per_game' => 58.0,
                'odds_market_keys' => ['player_pass_yds', 'season_player_pass_yds'],
            ],
            'passing_touchdowns' => [
                'label' => 'Passing Touchdowns',
                'stat_field' => 'passing_touchdowns',
                'positions' => ['QB'],
                'prior_per_game_by_position' => [
                    'QB' => 1.45,
                ],
                'prior_share_by_archetype' => [
                    'qb_starter' => 0.96,
                    'qb_backup' => 0.18,
                ],
                'default_stddev_per_game' => 1.15,
                'odds_market_keys' => ['player_pass_tds', 'season_player_pass_tds'],
            ],
            'rushing_yards' => [
                'label' => 'Rushing Yards',
                'stat_field' => 'rushing_yards',
                'positions' => ['QB', 'RB', 'WR'],
                'prior_per_game_by_position' => [
                    'QB' => 22.0,
                    'RB' => 52.0,
                    'WR' => 6.0,
                ],
                'prior_share_by_archetype' => [
                    'qb_starter' => 0.18,
                    'rb_lead' => 0.46,
                    'rb_rotation' => 0.22,
                    'wr_alpha' => 0.08,
                    'wr_secondary' => 0.04,
                ],
                'default_stddev_per_game' => 34.0,
                'odds_market_keys' => ['player_rush_yds', 'season_player_rush_yds'],
            ],
            'rushing_touchdowns' => [
                'label' => 'Rushing Touchdowns',
                'stat_field' => 'rushing_touchdowns',
                'positions' => ['QB', 'RB', 'WR'],
                'prior_per_game_by_position' => [
                    'QB' => 0.15,
                    'RB' => 0.45,
                    'WR' => 0.04,
                ],
                'prior_share_by_archetype' => [
                    'qb_starter' => 0.14,
                    'rb_lead' => 0.48,
                    'rb_rotation' => 0.20,
                    'wr_alpha' => 0.08,
                    'wr_secondary' => 0.04,
                ],
                'default_stddev_per_game' => 0.55,
                'odds_market_keys' => ['player_rush_tds', 'season_player_rush_tds'],
            ],
            'receptions' => [
                'label' => 'Receptions',
                'stat_field' => 'receptions',
                'positions' => ['RB', 'WR', 'TE'],
                'prior_per_game_by_position' => [
                    'RB' => 2.7,
                    'WR' => 4.3,
                    'TE' => 3.6,
                ],
                'prior_share_by_archetype' => [
                    'rb_lead' => 0.16,
                    'rb_rotation' => 0.09,
                    'wr_alpha' => 0.27,
                    'wr_secondary' => 0.15,
                    'te_primary' => 0.18,
                    'te_secondary' => 0.10,
                ],
                'default_stddev_per_game' => 2.1,
                'odds_market_keys' => ['player_receptions', 'season_player_receptions'],
            ],
            'receiving_yards' => [
                'label' => 'Receiving Yards',
                'stat_field' => 'receiving_yards',
                'positions' => ['RB', 'WR', 'TE'],
                'prior_per_game_by_position' => [
                    'RB' => 21.0,
                    'WR' => 53.0,
                    'TE' => 41.0,
                ],
                'prior_share_by_archetype' => [
                    'rb_lead' => 0.12,
                    'rb_rotation' => 0.07,
                    'wr_alpha' => 0.29,
                    'wr_secondary' => 0.16,
                    'te_primary' => 0.19,
                    'te_secondary' => 0.11,
                ],
                'default_stddev_per_game' => 28.0,
                'odds_market_keys' => ['player_reception_yds', 'season_player_reception_yds'],
            ],
            'receiving_touchdowns' => [
                'label' => 'Receiving Touchdowns',
                'stat_field' => 'receiving_touchdowns',
                'positions' => ['RB', 'WR', 'TE'],
                'prior_per_game_by_position' => [
                    'RB' => 0.15,
                    'WR' => 0.40,
                    'TE' => 0.32,
                ],
                'prior_share_by_archetype' => [
                    'rb_lead' => 0.14,
                    'rb_rotation' => 0.08,
                    'wr_alpha' => 0.28,
                    'wr_secondary' => 0.16,
                    'te_primary' => 0.19,
                    'te_secondary' => 0.11,
                ],
                'default_stddev_per_game' => 0.48,
                'odds_market_keys' => ['player_reception_tds', 'season_player_reception_tds'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Futures Projection Defaults
    |--------------------------------------------------------------------------
    |
    | A first-pass team win totals model anchored to current team metrics. It
    | blends current pace with predictive rating, future strength of schedule,
    | recent form, and injury adjustments into a remaining-games win estimate.
    |
    */
    'team_futures' => [
        'default_regular_season_games' => 17,
        'prior_win_pct' => 0.500,
        'prior_games' => 4,
        'strength_weight' => 0.55,
        'pace_weight' => 0.30,
        'prior_weight' => 0.15,
        'rating_scale' => 40.0,
        'recent_form_weight' => 18.0,
        'injury_adjustment_weight' => 0.35,
        'predictive_signal_scale' => 10.0,
        'recent_form_signal_scale' => 20.0,
        'sos_signal_scale' => 25.0,
        'injury_signal_scale' => 1.5,
        'win_total_probability_scale' => 0.90,
        'win_total_variance_floor' => 0.85,
        'preseason_prior_games' => 6,
        'preseason_prior_lookback_seasons' => 3,
        'preseason_prior_season_decay' => 0.55,
        'preseason_prior_predictive_decay' => 1.00,
        'preseason_prior_recent_form_decay' => 0.35,
        'offseason_qb_continuity_weight' => 2.0,
        'offseason_skill_continuity_weight' => 1.25,
        'offseason_skill_top_players' => 5,
        'offseason_skill_overlap_baseline' => 0.45,
        'offseason_max_injury_adjustment' => 0.75,
        'offseason_position_default_usage' => [
            'qb' => 0.35,
            'rb' => 0.10,
            'wr' => 0.08,
            'te' => 0.06,
        ],
        'offseason_injury_status_penalties' => [
            'out' => 1.0,
            'doubtful' => 0.7,
            'questionable' => 0.35,
            'day-to-day' => 0.2,
            'probable' => 0.1,
            'injured reserve' => 1.0,
            'ir' => 1.0,
            'suspended' => 0.85,
        ],
        'offseason_injury_position_weights' => [
            'qb' => 1.75,
            'rb' => 1.15,
            'wr' => 1.10,
            'te' => 1.0,
        ],
        'betting_probability_calibration' => [
            'default_shrink' => 0.70,
            'min_sample' => 20,
            'min_shrink' => 0.20,
            'max_shrink' => 1.00,
            'step' => 0.05,
        ],
        'markets' => [
            'season_wins' => [
                'label' => 'Season Wins',
                'odds_market_keys' => ['season_wins'],
            ],
        ],
    ],

    'team_playoff_forecast' => [
        'simulations' => 5000,
        'random_seed' => 20260402,
        'playoff_home_field_advantage' => 0.35,
        'win_probability_scale' => 1.6,
    ],

    'signals' => [
        'week_one_cover_min_edge' => env('NFL_SIGNALS_WEEK_ONE_COVER_MIN_EDGE', 1.5),
        'min_streak_length' => env('NFL_SIGNALS_MIN_STREAK_LENGTH', 3),
        'odds_stale_hours' => env('NFL_SIGNALS_ODDS_STALE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Calibration Defaults
    |--------------------------------------------------------------------------
    |
    | Default parameter ranges for calibration commands.
    |
    */

    'calibration' => [
        'spread' => [
            'min' => 0.01,
            'max' => 0.15,
            'step' => 0.005,
        ],

        'hfa' => [
            'min' => 0,
            'max' => 100,
            'step' => 5,
        ],

        'all_parameters' => [
            'quick' => [
                'hfa' => [25, 30, 35],
                'k_factor' => [18, 20, 22],
                'playoff_mult' => [1.4, 1.5, 1.6],
                'recency_mult' => [1.2, 1.3, 1.4],
                'mov_coef' => [0.20, 0.25, 0.30],
            ],
            'full' => [
                'hfa' => [20, 25, 30, 35, 40],
                'k_factor' => [16, 18, 20, 22, 24],
                'playoff_mult' => [1.3, 1.4, 1.5, 1.6, 1.7],
                'recency_mult' => [1.1, 1.2, 1.3, 1.4, 1.5],
                'mov_coef' => [0.15, 0.20, 0.25, 0.30, 0.35],
            ],
        ],
    ],
];
