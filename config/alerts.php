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
        'enabled' => env('DAILY_DIGEST_EMAILS_ENABLED', true),
        'sports' => ['mlb', 'nba', 'nfl', 'cbb', 'cfb', 'wcbb', 'wnba'],
        'sport_priority' => ['mlb', 'nfl', 'nba', 'cbb', 'cfb', 'wcbb', 'wnba'],
    ],

    'admin_report' => [
        'enabled' => env('ADMIN_EMAIL_REPORT_ENABLED', true),
        'recipients' => env('ADMIN_EMAIL_REPORT_RECIPIENTS', ''),
        'daily_time' => env('ADMIN_EMAIL_REPORT_DAILY_TIME', '11:30'),
    ],
];
