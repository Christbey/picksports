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
    | CFB season parameters and defaults.
    |
    */

    'season' => [
        'default' => env('CFB_DEFAULT_SEASON', 2026),
        'types' => [
            'preseason' => 1,
            'regular' => 2,
            'postseason' => 3,
        ],
        'weeks' => [
            'preseason' => 1,
            'regular' => 16,
            'postseason' => 4,
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
            'requests_per_minute' => env('CFB_API_RATE_LIMIT', 60),
            'delay_between_requests' => env('CFB_API_DELAY_MS', 100),
        ],
        'timeout' => env('CFB_API_TIMEOUT', 30),
        'retry' => [
            'enabled' => env('CFB_API_RETRY_ENABLED', true),
            'max_attempts' => env('CFB_API_RETRY_ATTEMPTS', 3),
            'delay' => env('CFB_API_RETRY_DELAY', 1000),
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
        'queue' => env('CFB_SYNC_QUEUE', 'default'),
        'batch_size' => env('CFB_SYNC_BATCH_SIZE', 50),
        'job_timeout' => env('CFB_SYNC_JOB_TIMEOUT', 300),
        'current_week_days_before' => 3,
        'current_week_days_after' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team Configuration
    |--------------------------------------------------------------------------
    |
    | Settings related to CFB teams.
    |
    */

    'teams' => [
        'divisions' => [
            'fbs' => 'FBS',
            'fcs' => 'FCS',
        ],
        'conferences' => [
            'fbs_count' => 10,
            'fcs_count' => 13,
        ],
        'power_conferences' => [
            'Southeastern Conference',
            'Big Ten Conference',
            'Big 12 Conference',
            'Atlantic Coast Conference',
        ],
        'group_of_five_conferences' => [
            'American Athletic Conference',
            'Conference USA',
            'Mid-American Conference',
            'Mountain West Conference',
            'Sun Belt Conference',
        ],
    ],

    'season_affiliations' => [
        'overrides' => [
            'NDSU' => [
                [
                    'end_season' => 2025,
                    'subdivision' => 'FCS',
                ],
                [
                    'start_season' => 2026,
                    'subdivision' => 'FBS',
                    'conference' => 'Mountain West Conference',
                    'division' => 'FBS',
                ],
            ],
        ],
    ],

    'metrics' => [
        'consensus_ratings' => [
            'weights' => [
                'fpi' => env('CFB_CONSENSUS_FPI_WEIGHT', 0.30),
                'power_rating' => env('CFB_CONSENSUS_POWER_RATING_WEIGHT', 0.25),
                'wepa_net' => env('CFB_CONSENSUS_WEPA_NET_WEIGHT', 0.20),
                'net_rating' => env('CFB_CONSENSUS_NET_RATING_WEIGHT', 0.10),
                'elo' => env('CFB_CONSENSUS_ELO_WEIGHT', 0.10),
                'cfp_rating' => env('CFB_CONSENSUS_CFP_WEIGHT', 0.03),
                'resume_rating' => env('CFB_CONSENSUS_RESUME_WEIGHT', 0.02),
            ],
        ],
        'injury_out_rating_penalty' => 18.0,
        'injury_questionable_rating_penalty' => 7.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | ELO Rating System Configuration
    |--------------------------------------------------------------------------
    |
    | These parameters control the ELO rating calculation for CFB teams.
    | Values have been calibrated for college football dynamics.
    |
    */

    'elo' => [
        /**
         * Default starting ELO rating for all teams
         */
        'default_rating' => 1500,

        /**
         * Offseason Elo regression toward mean before a new season starts.
         */
        'offseason_regression_factor' => 0.30,

        /**
         * Base K-factor for regular season games
         * Controls how much ratings change after each game
         */
        'base_k_factor' => 20,

        /**
         * Home field advantage in ELO points
         * College football typically has strong home field advantage
         */
        'home_field_advantage' => 55,

        /**
         * K-factor multiplier for playoff/bowl games
         * Postseason games have higher impact on ratings
         */
        'playoff_multiplier' => 1.5,

        /**
         * K-factor multiplier for early season games (weeks 1-4)
         * Higher volatility as teams establish identity
         */
        'recency_multiplier' => 1.2,

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
        'max_mov_multiplier' => 2.5,
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
        'points_per_elo' => 0.08,

        /**
         * Maximum predicted spread (points)
         * CFB spreads can be large due to talent disparity
         */
        'max_spread' => 40,

        /**
         * Minimum predicted spread (points)
         */
        'min_spread' => -40,

        /**
         * Average points per game for total estimation
         * Used as baseline when Elo-only prediction
         */
        'average_total' => 52,

        /**
         * Bounds for total projections.
         */
        'min_total' => 28,
        'max_total' => 88,

        /**
         * Advanced metric weights for CFB spread/total shaping.
         */
        'fpi_spread_weight' => 0.18,
        'wepa_spread_weight' => 4.5,
        'efficiency_spread_weight' => 0.04,
        'rating_consensus_spread_weight' => env('CFB_RATING_CONSENSUS_SPREAD_WEIGHT', 0.10),
        'rating_consensus_max_adjustment' => env('CFB_RATING_CONSENSUS_MAX_ADJUSTMENT', 2.5),
        'success_rate_spread_weight' => env('CFB_SUCCESS_RATE_SPREAD_WEIGHT', 14.0),
        'success_rate_max_adjustment' => env('CFB_SUCCESS_RATE_MAX_ADJUSTMENT', 2.0),
        'explosiveness_spread_weight' => env('CFB_EXPLOSIVENESS_SPREAD_WEIGHT', 3.0),
        'explosiveness_max_adjustment' => env('CFB_EXPLOSIVENESS_MAX_ADJUSTMENT', 1.5),
        'havoc_spread_weight' => env('CFB_HAVOC_SPREAD_WEIGHT', 10.0),
        'havoc_max_adjustment' => env('CFB_HAVOC_MAX_ADJUSTMENT', 1.25),
        'ol_qb_environment_spread_weight' => env('CFB_OL_QB_ENVIRONMENT_SPREAD_WEIGHT', 1.25),
        'ol_qb_environment_max_adjustment' => env('CFB_OL_QB_ENVIRONMENT_MAX_ADJUSTMENT', 1.5),
        'advanced_total_success_weight' => env('CFB_ADVANCED_TOTAL_SUCCESS_WEIGHT', 18.0),
        'advanced_total_explosiveness_weight' => env('CFB_ADVANCED_TOTAL_EXPLOSIVENESS_WEIGHT', 2.0),
        'advanced_total_havoc_weight' => env('CFB_ADVANCED_TOTAL_HAVOC_WEIGHT', 4.0),
        'advanced_total_max_adjustment' => env('CFB_ADVANCED_TOTAL_MAX_ADJUSTMENT', 3.0),
        'wepa_total_offense_weight' => 2.2,
        'wepa_total_defense_weight' => 1.4,
        'fpi_total_weight' => 0.08,

        /**
         * CFB margins often separate more than the raw projection implies.
         * Expand low/mid spreads only when independent quality signals agree.
         */
        'margin_calibration' => [
            'enabled' => env('CFB_MARGIN_CALIBRATION_ENABLED', true),
            'min_abs_spread' => env('CFB_MARGIN_CALIBRATION_MIN_ABS_SPREAD', 3.0),
            'max_abs_spread' => env('CFB_MARGIN_CALIBRATION_MAX_ABS_SPREAD', 21.0),
            'min_non_elo_signals' => env('CFB_MARGIN_CALIBRATION_MIN_NON_ELO_SIGNALS', 2),
            'max_bonus_points' => env('CFB_MARGIN_CALIBRATION_MAX_BONUS_POINTS', 6.0),
            'low_band_max' => env('CFB_MARGIN_CALIBRATION_LOW_BAND_MAX', 7.0),
            'mid_band_max' => env('CFB_MARGIN_CALIBRATION_MID_BAND_MAX', 14.0),
            'low_band_factor' => env('CFB_MARGIN_CALIBRATION_LOW_BAND_FACTOR', 1.80),
            'mid_band_factor' => env('CFB_MARGIN_CALIBRATION_MID_BAND_FACTOR', 1.45),
            'upper_band_factor' => env('CFB_MARGIN_CALIBRATION_UPPER_BAND_FACTOR', 1.20),
            'fpi_threshold' => env('CFB_MARGIN_CALIBRATION_FPI_THRESHOLD', 2.0),
            'wepa_net_threshold' => env('CFB_MARGIN_CALIBRATION_WEPA_NET_THRESHOLD', 0.35),
            'net_rating_threshold' => env('CFB_MARGIN_CALIBRATION_NET_RATING_THRESHOLD', 3.0),
        ],

        /**
         * When current-season team metrics do not exist yet, reuse the latest
         * prior-season metrics for preseason / early schedule predictions.
         */
        'use_previous_season_metrics_fallback' => true,

        /**
         * Use the latest same-season Elo before kickoff, falling back to a
         * regressed prior-season Elo for Week 0 through the early season.
         */
        'use_previous_season_elo_fallback' => env('CFB_USE_PREVIOUS_SEASON_ELO_FALLBACK', true),
        'previous_season_elo_fallback_through_week' => env('CFB_PREVIOUS_SEASON_ELO_FALLBACK_THROUGH_WEEK', 4),
        'previous_season_elo_regression_factor' => env(
            'CFB_PREVIOUS_SEASON_ELO_REGRESSION_FACTOR',
            env('CFB_OFFSEASON_ELO_REGRESSION_FACTOR', 0.30)
        ),

        /**
         * Preseason / early-season signal layer. This runs after the base
         * model and shared context adjustments, then final caps are applied.
         *
         * predicted_spread remains model home margin. Sportsbook home spread
         * lines are converted by negating before market comparisons.
         */
        'preseason' => [
            'enabled' => env('CFB_PRESEASON_LAYER_ENABLED', true),
            'through_week' => env('CFB_PRESEASON_LAYER_THROUGH_WEEK', 4),
            'signal_table' => env('CFB_PRESEASON_SIGNAL_TABLE', 'cfb_preseason_team_signals'),
            'prior_season_fallback_limit' => env('CFB_PRESEASON_PRIOR_SEASON_FALLBACK_LIMIT', 5),
            'min_confidence_after_penalties' => env('CFB_PRESEASON_MIN_CONFIDENCE', 50.0),

            'composite' => [
                'power_rating_weight' => env('CFB_PRESEASON_POWER_RATING_WEIGHT', 0.08),
                'fpi_weight' => env('CFB_PRESEASON_FPI_WEIGHT', 0.04),
                'net_rating_weight' => env('CFB_PRESEASON_NET_RATING_WEIGHT', 0.025),
                'max_adjustment' => env('CFB_PRESEASON_COMPOSITE_MAX_ADJUSTMENT', 3.0),
            ],

            'returning_production' => [
                'points_per_full_retention_gap' => env('CFB_PRESEASON_RETURNING_PRODUCTION_POINTS', 8.0),
                'max_adjustment' => env('CFB_PRESEASON_RETURNING_PRODUCTION_MAX_ADJUSTMENT', 2.5),
            ],

            'qb_continuity' => [
                'points_per_score_gap' => env('CFB_PRESEASON_QB_CONTINUITY_POINTS', 1.5),
                'max_adjustment' => env('CFB_PRESEASON_QB_CONTINUITY_MAX_ADJUSTMENT', 2.0),
                'uncertainty_score_threshold' => env('CFB_PRESEASON_QB_UNCERTAINTY_THRESHOLD', 0.0),
                'confidence_penalty_per_uncertain_side' => env('CFB_PRESEASON_QB_UNCERTAINTY_PENALTY', 2.5),
                'status_scores' => [
                    'returning_starter' => 1.0,
                    'experienced_transfer' => 0.35,
                    'injury_return' => 0.15,
                    'new_transfer' => -0.35,
                    'first_time_starter' => -0.75,
                    'unsettled' => -0.8,
                ],
            ],

            'transfer_portal' => [
                'points_per_score_gap' => env('CFB_PRESEASON_TRANSFER_PORTAL_POINTS', 2.5),
                'max_adjustment' => env('CFB_PRESEASON_TRANSFER_PORTAL_MAX_ADJUSTMENT', 2.0),
                'value_normalizer' => env('CFB_PRESEASON_TRANSFER_PORTAL_VALUE_NORMALIZER', 4.0),
                'uncertainty_score_threshold' => env('CFB_PRESEASON_TRANSFER_UNCERTAINTY_THRESHOLD', -0.25),
                'confidence_penalty_per_uncertain_side' => env('CFB_PRESEASON_TRANSFER_UNCERTAINTY_PENALTY', 1.5),
            ],

            'talent_recruiting' => [
                'points_per_score_gap' => env('CFB_PRESEASON_TALENT_RECRUITING_POINTS', 4.0),
                'max_adjustment' => env('CFB_PRESEASON_TALENT_RECRUITING_MAX_ADJUSTMENT', 1.5),
                'talent_composite_scale' => env('CFB_PRESEASON_TALENT_COMPOSITE_SCALE', 1000.0),
                'recruiting_points_scale' => env('CFB_PRESEASON_RECRUITING_POINTS_SCALE', 350.0),
                'recruiting_rank_team_count' => env('CFB_PRESEASON_RECRUITING_RANK_TEAM_COUNT', 134.0),
            ],

            'coaching_continuity' => [
                'points_per_score_gap' => env('CFB_PRESEASON_COACHING_POINTS', 0.8),
                'max_adjustment' => env('CFB_PRESEASON_COACHING_MAX_ADJUSTMENT', 1.0),
                'uncertainty_score_threshold' => env('CFB_PRESEASON_COACHING_UNCERTAINTY_THRESHOLD', 0.0),
                'confidence_penalty_per_uncertain_side' => env('CFB_PRESEASON_COACHING_UNCERTAINTY_PENALTY', 2.0),
                'status_scores' => [
                    'stable' => 1.0,
                    'returning_staff' => 1.0,
                    'new_coordinator' => 0.25,
                    'new_oc' => 0.35,
                    'new_dc' => 0.35,
                    'new_head_coach' => -1.0,
                    'new_staff' => -1.0,
                ],
            ],

            'coaching_scheme' => [
                'points_per_score_gap' => env('CFB_PRESEASON_COACHING_SCHEME_POINTS', 0.7),
                'max_adjustment' => env('CFB_PRESEASON_COACHING_SCHEME_MAX_ADJUSTMENT', 1.0),
                'total_points_per_score' => env('CFB_PRESEASON_COACHING_SCHEME_TOTAL_POINTS', 0.8),
                'max_total_adjustment' => env('CFB_PRESEASON_COACHING_SCHEME_TOTAL_MAX_ADJUSTMENT', 1.25),
                'volatility_threshold' => env('CFB_PRESEASON_COACHING_SCHEME_VOLATILITY_THRESHOLD', 0.55),
                'confidence_penalty_per_volatile_side' => env('CFB_PRESEASON_COACHING_SCHEME_VOLATILITY_PENALTY', 1.5),
            ],

            'special_teams' => [
                'spread_weight' => env('CFB_PRESEASON_SPECIAL_TEAMS_SPREAD_WEIGHT', 0.15),
                'max_adjustment' => env('CFB_PRESEASON_SPECIAL_TEAMS_MAX_ADJUSTMENT', 1.25),
                'total_weight' => env('CFB_PRESEASON_SPECIAL_TEAMS_TOTAL_WEIGHT', 0.04),
                'max_total_adjustment' => env('CFB_PRESEASON_SPECIAL_TEAMS_TOTAL_MAX_ADJUSTMENT', 1.0),
                'mismatch_threshold' => env('CFB_PRESEASON_SPECIAL_TEAMS_MISMATCH_THRESHOLD', 4.0),
            ],

            'schedule_spot' => [
                'rest_day_weight' => env('CFB_PRESEASON_REST_DAY_WEIGHT', 0.25),
                'travel_1000_miles_weight' => env('CFB_PRESEASON_TRAVEL_1000_MILES_WEIGHT', 0.35),
                'max_adjustment' => env('CFB_PRESEASON_SCHEDULE_SPOT_MAX_ADJUSTMENT', 1.25),
            ],

            'market_guardrail' => [
                'enabled' => env('CFB_PRESEASON_MARKET_GUARDRAIL_ENABLED', true),
                'through_week' => env('CFB_PRESEASON_MARKET_GUARDRAIL_THROUGH_WEEK', 2),
                'large_disagreement_threshold' => env('CFB_PRESEASON_MARKET_DISAGREEMENT_THRESHOLD', 10.0),
                'required_aligned_signals' => env('CFB_PRESEASON_MARKET_REQUIRED_ALIGNED_SIGNALS', 3),
                'confirmed_disagreement_penalty' => env('CFB_PRESEASON_MARKET_CONFIRMED_PENALTY', 3.0),
                'unconfirmed_disagreement_penalty' => env('CFB_PRESEASON_MARKET_UNCONFIRMED_PENALTY', 12.0),
            ],
        ],

        /**
         * Week-bucket calibration hooks. Defaults are no-op so backtesting can
         * tune the buckets independently without changing code.
         */
        'week_calibration' => [
            'enabled' => env('CFB_WEEK_CALIBRATION_ENABLED', true),
            'buckets' => [
                'week_0_1' => [
                    'spread_multiplier' => env('CFB_WEEK_0_1_SPREAD_MULTIPLIER', 1.0),
                    'spread_adjustment' => env('CFB_WEEK_0_1_SPREAD_ADJUSTMENT', 0.0),
                    'total_adjustment' => env('CFB_WEEK_0_1_TOTAL_ADJUSTMENT', 0.0),
                    'confidence_penalty' => env('CFB_WEEK_0_1_CONFIDENCE_PENALTY', 0.0),
                ],
                'week_2_4' => [
                    'spread_multiplier' => env('CFB_WEEK_2_4_SPREAD_MULTIPLIER', 1.0),
                    'spread_adjustment' => env('CFB_WEEK_2_4_SPREAD_ADJUSTMENT', 0.0),
                    'total_adjustment' => env('CFB_WEEK_2_4_TOTAL_ADJUSTMENT', 0.0),
                    'confidence_penalty' => env('CFB_WEEK_2_4_CONFIDENCE_PENALTY', 0.0),
                ],
                'week_5_8' => [
                    'spread_multiplier' => env('CFB_WEEK_5_8_SPREAD_MULTIPLIER', 1.0),
                    'spread_adjustment' => env('CFB_WEEK_5_8_SPREAD_ADJUSTMENT', 0.0),
                    'total_adjustment' => env('CFB_WEEK_5_8_TOTAL_ADJUSTMENT', 0.0),
                    'confidence_penalty' => env('CFB_WEEK_5_8_CONFIDENCE_PENALTY', 0.0),
                ],
                'week_9_plus' => [
                    'spread_multiplier' => env('CFB_WEEK_9_PLUS_SPREAD_MULTIPLIER', 1.0),
                    'spread_adjustment' => env('CFB_WEEK_9_PLUS_SPREAD_ADJUSTMENT', 0.0),
                    'total_adjustment' => env('CFB_WEEK_9_PLUS_TOTAL_ADJUSTMENT', 0.0),
                    'confidence_penalty' => env('CFB_WEEK_9_PLUS_CONFIDENCE_PENALTY', 0.0),
                ],
            ],
        ],

        /**
         * Adaptive season-to-date calibration learned from graded CFB games.
         * The command writes bounded overrides; prediction generation applies
         * the latest active profile when this switch is enabled.
         */
        'adaptive_calibration' => [
            'enabled' => env('CFB_ADAPTIVE_CALIBRATION_ENABLED', true),
            'min_games_per_bucket' => env('CFB_ADAPTIVE_CALIBRATION_MIN_GAMES', 8),
            'min_component_games' => env('CFB_ADAPTIVE_CALIBRATION_MIN_COMPONENT_GAMES', 6),
            'learning_rate' => env('CFB_ADAPTIVE_CALIBRATION_LEARNING_RATE', 0.25),
            'component_learning_rate' => env('CFB_ADAPTIVE_CALIBRATION_COMPONENT_LEARNING_RATE', 0.15),
            'max_spread_adjustment' => env('CFB_ADAPTIVE_CALIBRATION_MAX_SPREAD_ADJUSTMENT', 3.0),
            'max_total_adjustment' => env('CFB_ADAPTIVE_CALIBRATION_MAX_TOTAL_ADJUSTMENT', 3.0),
            'max_component_multiplier_delta' => env('CFB_ADAPTIVE_CALIBRATION_MAX_COMPONENT_DELTA', 0.25),
            'min_component_multiplier' => env('CFB_ADAPTIVE_CALIBRATION_MIN_COMPONENT_MULTIPLIER', 0.75),
            'max_component_multiplier' => env('CFB_ADAPTIVE_CALIBRATION_MAX_COMPONENT_MULTIPLIER', 1.25),
            'target_winner_accuracy' => env('CFB_ADAPTIVE_CALIBRATION_TARGET_WINNER_ACCURACY', 58.0),
            'confidence_penalty_points_per_accuracy_gap' => env('CFB_ADAPTIVE_CALIBRATION_CONFIDENCE_PENALTY_POINTS', 0.20),
            'max_confidence_penalty' => env('CFB_ADAPTIVE_CALIBRATION_MAX_CONFIDENCE_PENALTY', 4.0),
        ],

        /**
         * Market movement signal layer. Spread values are normalized into the
         * same home-margin convention as predicted_spread before comparison.
         */
        'market_movement' => [
            'enabled' => env('CFB_MARKET_MOVEMENT_ENABLED', true),
            'apply_confidence_adjustment' => env('CFB_MARKET_MOVEMENT_CONFIDENCE_ENABLED', true),
            'pick_side_threshold' => env('CFB_MARKET_MOVEMENT_PICK_SIDE_THRESHOLD', 0.5),
            'min_movement_points' => env('CFB_MARKET_MOVEMENT_MIN_POINTS', 1.0),
            'confidence_boost_toward_model' => env('CFB_MARKET_MOVEMENT_CONFIDENCE_BOOST', 1.5),
            'confidence_penalty_against_model' => env('CFB_MARKET_MOVEMENT_CONFIDENCE_PENALTY', 2.0),
            'max_confidence_adjustment' => env('CFB_MARKET_MOVEMENT_MAX_CONFIDENCE_ADJUSTMENT', 3.0),
            'book_disagreement_threshold' => env('CFB_MARKET_MOVEMENT_BOOK_DISAGREEMENT_THRESHOLD', 1.0),
            'book_disagreement_penalty' => env('CFB_MARKET_MOVEMENT_BOOK_DISAGREEMENT_PENALTY', 1.0),
            'min_current_books' => env('CFB_MARKET_MOVEMENT_MIN_CURRENT_BOOKS', 1),
        ],

        /**
         * Game-level context signals. These are intentionally bounded and
         * mostly no-op unless stored weather or nearby schedule context exists.
         */
        'game_context' => [
            'enabled' => env('CFB_GAME_CONTEXT_ENABLED', true),
            'max_spread_adjustment' => env('CFB_GAME_CONTEXT_MAX_SPREAD_ADJUSTMENT', 2.0),
            'max_total_adjustment' => env('CFB_GAME_CONTEXT_MAX_TOTAL_ADJUSTMENT', 4.0),
            'max_confidence_penalty' => env('CFB_GAME_CONTEXT_MAX_CONFIDENCE_PENALTY', 5.0),
            'indoor_venue_keywords' => [
                'dome',
                'superdome',
                'alamodome',
                'allegiant',
                'sofi',
                'ford field',
                'lucas oil',
                'mercedes-benz',
                'nrg stadium',
                'at&t stadium',
            ],
            'weather' => [
                'wind_under_threshold_mph' => env('CFB_WEATHER_WIND_UNDER_THRESHOLD_MPH', 15.0),
                'gust_under_threshold_mph' => env('CFB_WEATHER_GUST_UNDER_THRESHOLD_MPH', 24.0),
                'precip_under_threshold_inches' => env('CFB_WEATHER_PRECIP_UNDER_THRESHOLD_INCHES', 0.03),
                'precip_probability_threshold' => env('CFB_WEATHER_PRECIP_PROBABILITY_THRESHOLD', 55.0),
                'cold_under_threshold_f' => env('CFB_WEATHER_COLD_UNDER_THRESHOLD_F', 32.0),
                'heat_under_threshold_f' => env('CFB_WEATHER_HEAT_UNDER_THRESHOLD_F', 88.0),
                'wind_total_weight' => env('CFB_WEATHER_WIND_TOTAL_WEIGHT', -0.08),
                'gust_total_weight' => env('CFB_WEATHER_GUST_TOTAL_WEIGHT', -0.04),
                'precip_total_weight' => env('CFB_WEATHER_PRECIP_TOTAL_WEIGHT', -18.0),
                'precip_probability_total_adjustment' => env('CFB_WEATHER_PRECIP_PROBABILITY_TOTAL_ADJUSTMENT', -0.5),
                'cold_total_adjustment' => env('CFB_WEATHER_COLD_TOTAL_ADJUSTMENT', -0.8),
                'heat_total_adjustment' => env('CFB_WEATHER_HEAT_TOTAL_ADJUSTMENT', -0.4),
                'indoor_total_adjustment' => env('CFB_WEATHER_INDOOR_TOTAL_ADJUSTMENT', 0.4),
                'adverse_weather_confidence_penalty' => env('CFB_WEATHER_CONFIDENCE_PENALTY', 1.0),
                'max_total_adjustment' => env('CFB_WEATHER_MAX_TOTAL_ADJUSTMENT', 4.0),
                'venue_coordinates' => [],
            ],
            'venue' => [
                'rivalry_pairs' => [],
                'rivalry_total_adjustment' => env('CFB_RIVALRY_TOTAL_ADJUSTMENT', -0.3),
                'rivalry_confidence_penalty' => env('CFB_RIVALRY_CONFIDENCE_PENALTY', 0.5),
            ],
            'schedule' => [
                'short_rest_days' => env('CFB_CONTEXT_SHORT_REST_DAYS', 5),
                'extra_rest_days' => env('CFB_CONTEXT_EXTRA_REST_DAYS', 9),
                'rest_diff_weight' => env('CFB_CONTEXT_REST_DIFF_WEIGHT', 0.08),
                'short_rest_spread_penalty' => env('CFB_CONTEXT_SHORT_REST_SPREAD_PENALTY', -0.25),
                'short_rest_total_adjustment' => env('CFB_CONTEXT_SHORT_REST_TOTAL_ADJUSTMENT', -0.2),
                'short_rest_confidence_penalty' => env('CFB_CONTEXT_SHORT_REST_CONFIDENCE_PENALTY', 0.5),
                'extra_rest_spread_bonus' => env('CFB_CONTEXT_EXTRA_REST_SPREAD_BONUS', 0.15),
                'consecutive_road_spread_penalty' => env('CFB_CONTEXT_CONSECUTIVE_ROAD_SPREAD_PENALTY', 0.25),
                'consecutive_road_confidence_penalty' => env('CFB_CONTEXT_CONSECUTIVE_ROAD_CONFIDENCE_PENALTY', 0.5),
                'lookahead_window_days' => env('CFB_CONTEXT_LOOKAHEAD_WINDOW_DAYS', 9),
                'lookahead_elo_gap' => env('CFB_CONTEXT_LOOKAHEAD_ELO_GAP', 120.0),
                'lookahead_spread_penalty' => env('CFB_CONTEXT_LOOKAHEAD_SPREAD_PENALTY', 0.25),
                'lookahead_confidence_penalty' => env('CFB_CONTEXT_LOOKAHEAD_CONFIDENCE_PENALTY', 0.5),
                'letdown_elo_gap' => env('CFB_CONTEXT_LETDOWN_ELO_GAP', 120.0),
                'letdown_spread_penalty' => env('CFB_CONTEXT_LETDOWN_SPREAD_PENALTY', 0.2),
                'letdown_confidence_penalty' => env('CFB_CONTEXT_LETDOWN_CONFIDENCE_PENALTY', 0.5),
                'max_spread_adjustment' => env('CFB_CONTEXT_SCHEDULE_MAX_SPREAD_ADJUSTMENT', 1.25),
                'max_total_adjustment' => env('CFB_CONTEXT_SCHEDULE_MAX_TOTAL_ADJUSTMENT', 1.0),
            ],
            'persisted' => [
                'applied_families' => ['market_movement'],
                'max_spread_adjustment' => env('CFB_PERSISTED_CONTEXT_MAX_SPREAD_ADJUSTMENT', 3.0),
                'max_total_adjustment' => env('CFB_PERSISTED_CONTEXT_MAX_TOTAL_ADJUSTMENT', 4.0),
                'max_confidence_penalty' => env('CFB_PERSISTED_CONTEXT_MAX_CONFIDENCE_PENALTY', 5.0),
            ],
        ],

        /**
         * Model version for tracking prediction algorithm changes
         */
        'model_version' => '1.2',

        /**
         * Confidence scoring parameters
         */
        'confidence' => [
            'base' => 50,
            'home_non_default_elo' => 25,
            'away_non_default_elo' => 25,
        ],

        /**
         * Injury adjustments
         */
        'injury_out_spread_penalty' => 0.50,
        'injury_questionable_spread_penalty' => 0.20,
        'injury_out_total_penalty' => 0.30,
        'injury_questionable_total_penalty' => 0.10,

        'player_availability' => [
            'enabled' => env('CFB_PLAYER_AVAILABILITY_WEIGHTING_ENABLED', true),
            'lookback_games' => env('CFB_PLAYER_AVAILABILITY_LOOKBACK_GAMES', 8),
            'min_player_multiplier' => env('CFB_PLAYER_AVAILABILITY_MIN_MULTIPLIER', 0.35),
            'max_player_multiplier' => env('CFB_PLAYER_AVAILABILITY_MAX_MULTIPLIER', 3.0),
            'unknown_player_multiplier' => env('CFB_PLAYER_AVAILABILITY_UNKNOWN_MULTIPLIER', 0.75),
            'max_spread_adjustment' => env('CFB_PLAYER_AVAILABILITY_MAX_SPREAD_ADJUSTMENT', 4.5),
            'max_total_adjustment' => env('CFB_PLAYER_AVAILABILITY_MAX_TOTAL_ADJUSTMENT', 6.0),
            'availability_weights' => [
                'out' => 1.00,
                'doubtful' => 0.85,
                'questionable' => 0.55,
                'probable' => 0.25,
            ],
            'position_weights' => [
                'QB' => 2.20,
                'RB' => 1.10,
                'WR' => 1.00,
                'TE' => 0.95,
                'OL' => 1.20,
                'DL' => 1.10,
                'LB' => 1.00,
                'DB' => 1.00,
                'K' => 0.60,
                'P' => 0.35,
                'OTHER' => 0.75,
            ],
            'unknown_production_multipliers' => [
                'QB' => 1.15,
                'OL' => 0.95,
            ],
            'production_baselines' => [
                'QB' => 18.0,
                'RB' => 10.0,
                'WR' => 8.0,
                'TE' => 6.0,
                'DL' => 4.5,
                'LB' => 5.5,
                'DB' => 4.5,
                'K' => 2.2,
                'OTHER' => 6.0,
            ],
        ],
    ],

];
