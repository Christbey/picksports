<?php

return [
    'window_days' => 7,

    'regression_alerts' => [
        'enabled' => env('VALIDATION_REGRESSION_ALERTS_ENABLED', true),
        'failing_delta_threshold' => env('VALIDATION_REGRESSION_FAILING_DELTA_THRESHOLD', 1),
        'warning_delta_threshold' => env('VALIDATION_REGRESSION_WARNING_DELTA_THRESHOLD', 2),
    ],

    'sports' => [
        'mlb' => [
            'tables' => ['teams' => 'mlb_teams', 'games' => 'mlb_games', 'team_stats' => 'mlb_team_stats'],
            'models' => ['game' => \App\Models\MLB\Game::class],
            'active_months' => [3, 4, 5, 6, 7, 8, 9, 10],
            'expected_games_per_day' => 10,
        ],
        'nba' => [
            'tables' => ['teams' => 'nba_teams', 'games' => 'nba_games', 'team_stats' => 'nba_team_stats'],
            'models' => ['game' => \App\Models\NBA\Game::class],
            'active_months' => [10, 11, 12, 1, 2, 3, 4, 5, 6],
            'expected_games_per_day' => 5,
        ],
        'nfl' => [
            'tables' => ['teams' => 'nfl_teams', 'games' => 'nfl_games', 'team_stats' => 'nfl_team_stats'],
            'models' => ['game' => \App\Models\NFL\Game::class],
            'active_months' => [9, 10, 11, 12, 1, 2],
            'expected_games_per_day' => 1,
        ],
        'cbb' => [
            'tables' => ['teams' => 'cbb_teams', 'games' => 'cbb_games', 'team_stats' => 'cbb_team_stats'],
            'models' => ['game' => \App\Models\CBB\Game::class],
            'active_months' => [11, 12, 1, 2, 3, 4],
            'expected_games_per_day' => 20,
        ],
        'cfb' => [
            'tables' => ['teams' => 'cfb_teams', 'games' => 'cfb_games', 'team_stats' => 'cfb_team_stats'],
            'models' => ['game' => \App\Models\CFB\Game::class],
            'active_months' => [8, 9, 10, 11, 12, 1],
            'expected_games_per_day' => 10,
        ],
        'wcbb' => [
            'tables' => ['teams' => 'wcbb_teams', 'games' => 'wcbb_games', 'team_stats' => 'wcbb_team_stats'],
            'models' => ['game' => \App\Models\WCBB\Game::class],
            'active_months' => [11, 12, 1, 2, 3, 4],
            'expected_games_per_day' => 20,
        ],
        'wnba' => [
            'tables' => ['teams' => 'wnba_teams', 'games' => 'wnba_games', 'team_stats' => 'wnba_team_stats'],
            'models' => ['game' => \App\Models\WNBA\Game::class],
            'active_months' => [5, 6, 7, 8, 9],
            'expected_games_per_day' => 2,
        ],
    ],

    'thresholds' => [
        'game_coverage' => [
            'missing_teams_warn_pct' => 0.0,
            'missing_teams_fail_pct' => 0.05,
            'min_upcoming_games_factor' => 0.5,
        ],
        'team_stat_coverage' => [
            'missing_teams_warn_pct' => 0.0,
            'missing_teams_fail_pct' => 0.05,
        ],
        'prediction_completeness' => [
            'missing_warn_pct' => 0.05,
            'missing_fail_pct' => 0.20,
        ],
        'odds_completeness' => [
            'problem_warn_pct' => 0.05,
            'problem_fail_pct' => 0.20,
            'stale_after_hours' => 8,
        ],
        'finalized_data_completeness' => [
            'problem_warn_pct' => 0.05,
            'problem_fail_pct' => 0.20,
            'lookback_days' => 14,
            'grading_grace_hours' => 6,
        ],
    ],
];
