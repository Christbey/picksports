<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Alert Thresholds
    |--------------------------------------------------------------------------
    |
    | Minimum thresholds for triggering alerts
    |
    */

    'thresholds' => [
        'min_confidence' => 60,    // Minimum prediction confidence (0-100)
        'min_edge_percent' => 2.5, // Minimum betting edge percentage
    ],

    'daily_digest' => [
        'sports' => ['mlb', 'nba', 'nfl', 'cbb', 'cfb', 'wcbb', 'wnba'],
        'sport_priority' => ['mlb', 'nfl', 'nba', 'cbb', 'cfb', 'wcbb', 'wnba'],
    ],
];
