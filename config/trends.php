<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trend Calculation Defaults
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'sample_size' => 16,
        'min_sample' => 5,
        'max_sample' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | League-Specific Thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'nfl' => [
            'scoring' => [21, 24, 28, 35],
            'margin' => [3, 7, 10, 14],
            'win_percentage' => 0.6,
            'close_game_margin' => 7,
            'short_rest_days' => 6,
            'primetime_networks' => ['NBC', 'ESPN', 'ABC', 'NFL Network', 'Amazon Prime Video'],
            'early_game_hour' => 13,
            'late_game_hour' => 20,
        ],
        'nba' => [
            'scoring' => [100, 110, 115, 120],
            'margin' => [5, 10, 15, 20],
            'win_percentage' => 0.6,
            'close_game_margin' => 5,
            'short_rest_days' => 1,
            'primetime_networks' => ['ESPN', 'TNT', 'ABC', 'NBA TV'],
            'early_game_hour' => 13,
            'late_game_hour' => 22,
        ],
        'cbb' => [
            'scoring' => [65, 70, 75, 80],
            'margin' => [5, 10, 15],
            'win_percentage' => 0.6,
            'close_game_margin' => 5,
            'short_rest_days' => 2,
            'primetime_networks' => ['ESPN', 'CBS', 'FOX'],
            'early_game_hour' => 12,
            'late_game_hour' => 21,
        ],
        'wcbb' => [
            'scoring' => [60, 65, 70, 75],
            'margin' => [5, 10, 15],
            'win_percentage' => 0.6,
            'close_game_margin' => 5,
            'short_rest_days' => 2,
            'primetime_networks' => ['ESPN', 'ESPN2', 'ABC'],
            'early_game_hour' => 12,
            'late_game_hour' => 21,
        ],
        'cfb' => [
            'scoring' => [21, 28, 35, 42],
            'margin' => [7, 14, 21],
            'win_percentage' => 0.6,
            'close_game_margin' => 7,
            'short_rest_days' => 6,
            'primetime_networks' => ['ESPN', 'ABC', 'FOX', 'CBS'],
            'early_game_hour' => 12,
            'late_game_hour' => 20,
        ],
        'wnba' => [
            'scoring' => [75, 80, 85, 90],
            'margin' => [5, 10, 15],
            'win_percentage' => 0.6,
            'close_game_margin' => 5,
            'short_rest_days' => 1,
            'primetime_networks' => ['ESPN', 'ESPN2', 'ABC', 'CBS Sports Network'],
            'early_game_hour' => 12,
            'late_game_hour' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Enabled Collectors
    |--------------------------------------------------------------------------
    */
    'collectors' => [
        'scoring' => true,
        'quarters' => true,
        'halves' => true,
        'margins' => true,
        'totals' => true,
        'first_score' => true,
        'situational' => true,
        'streaks' => true,
        'advanced' => true,
        'time_based' => true,
        'rest_schedule' => true,
        'opponent_strength' => true,
        'conference' => true,
        'scoring_patterns' => true,
        'drive_efficiency' => true,
        'offensive_efficiency' => true,
        'defensive_performance' => true,
        'momentum' => true,
        'clutch_performance' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier Restrictions
    |--------------------------------------------------------------------------
    | Minimum tier required to access each collector category.
    | Tiers hierarchy: free → basic → pro → premium
    */
    'tier_requirements' => [
        // Free tier - basic trends
        'scoring' => 'free',
        'streaks' => 'free',
        'margins' => 'free',

        // Basic tier - expanded trends
        'quarters' => 'basic',
        'halves' => 'basic',
        'totals' => 'basic',
        'first_score' => 'basic',
        'situational' => 'basic',

        // Pro tier - advanced analytics
        'advanced' => 'pro',
        'time_based' => 'pro',
        'rest_schedule' => 'pro',
        'opponent_strength' => 'pro',
        'conference' => 'pro',

        // Premium tier - deep analytics
        'scoring_patterns' => 'premium',
        'drive_efficiency' => 'premium',
        'offensive_efficiency' => 'premium',
        'defensive_performance' => 'premium',
        'momentum' => 'premium',
        'clutch_performance' => 'premium',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tier Hierarchy
    |--------------------------------------------------------------------------
    | Numeric values for tier comparison (higher = more access)
    */
    'tier_levels' => [
        'free' => 0,
        'basic' => 1,
        'pro' => 2,
        'premium' => 3,
    ],
];
