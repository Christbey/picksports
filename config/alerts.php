<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Alert Digest Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for daily betting digest emails including tier limits,
    | ranking weights, and diversification rules.
    |
    */

    'digest' => [
        // Default time for daily digest delivery (HH:MM:SS format)
        'default_time' => '10:00:00',

        // Number of top bets to include per subscription tier
        'bets_per_tier' => [
            'free' => 3,
            'basic' => 5,
            'pro' => 8,
            'premium' => 10,
        ],

        // Ranking weights for multi-factor scoring (must sum to 1.0)
        'ranking_weights' => [
            'edge' => 0.40,        // Betting edge magnitude (40%)
            'confidence' => 0.30,  // Model confidence score (30%)
            'kelly_size' => 0.20,  // Kelly criterion bet sizing (20%)
            'bet_type' => 0.10,    // Bet type quality preference (10%)
        ],

        // Bet type quality scores (higher = preferred)
        'bet_type_scores' => [
            'moneyline' => 1.0,    // Straight win/loss bets
            'spread' => 0.8,       // Point spread bets
            'total' => 0.6,        // Over/under bets
        ],

        // Diversification rules to prevent over-concentration
        'diversification' => [
            'max_same_type' => 4,  // Maximum bets of the same type in digest
        ],

        // Whether to send digest even when no qualifying bets found
        'send_empty' => true,

        // Time window (in minutes) around digest_time to send digests
        // e.g., 30 means digests scheduled for 10:00 will send between 9:30-10:30
        'time_window_minutes' => 30,
    ],

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
];
