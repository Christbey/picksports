<?php

return [
    'enabled' => true,
    'version' => 'cfb-forecast-coordination-v2',
    'policy' => [
        'margin_alternative_weight' => 0,
        'total_alternative_weight' => 0,
        'margin_market_weight' => 0.1,
        'total_market_weight' => 0.1,
    ],
    'policy_hash' => '680d44769f33190f0b479b24bd3578cdd970beb2f6d861f581fdcc71a94839b1',
    'formula_hash' => '8ccaa5a21f8921d03897a746459d2c272a68dadf382dd9d120ce7cca99def9a5',
    'available_at' => '2026-10-16T21:32:12-05:00',
    'training_games' => 68,
    'training_hash' => 'e6c41fb3112a4241fd27f003c4b885b57ef98fc87b7f19322ed3f1528e5b1f61',
    'trained_through' => '2026-10-09T21:30:00-05:00',
    'evaluation_games' => 49,
    'historical_accuracy_checks_passed' => true,
    'evaluation_hash' => '40624cfa9fd61ca714ff9717761357a04fc84c4eac573c98e0ea3a96213c3887',
    'historical_mean_absolute_errors' => [
        'published_margin' => '11.495918',
        'coordinated_margin' => '11.456490',
        'published_total' => '11.822449',
        'coordinated_total' => '11.649388',
    ],
    'historical_winner_records' => [
        'published' => [
            'win' => 32,
            'loss' => 17,
            'undecided' => 0,
        ],
        'coordinated' => [
            'win' => 32,
            'loss' => 17,
            'undecided' => 0,
        ],
    ],
    'evidence_status' => 'historically_evaluated_limited_sample',
    'prospectively_validated' => false,
    'revision_disclosure' => 'Market winner-preservation constraint was added after the first historical candidate lost winner accuracy; the revised historical comparison is exploratory.',
];
