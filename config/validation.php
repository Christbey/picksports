<?php

use App\Models\MLB\Game;

return [
    'window_days' => 7,
    'market_window_days' => 1,

    'regression_alerts' => [
        'enabled' => env('VALIDATION_REGRESSION_ALERTS_ENABLED', true),
        'failing_delta_threshold' => env('VALIDATION_REGRESSION_FAILING_DELTA_THRESHOLD', 1),
        'warning_delta_threshold' => env('VALIDATION_REGRESSION_WARNING_DELTA_THRESHOLD', 2),
    ],

    'sports' => [
        'mlb' => [
            'tables' => ['teams' => 'mlb_teams', 'games' => 'mlb_games', 'team_stats' => 'mlb_team_stats', 'player_stats' => 'mlb_player_stats', 'plays' => 'mlb_plays', 'weather' => 'mlb_game_weather', 'injuries' => 'mlb_player_injuries', 'player_props' => 'mlb_player_props'],
            'models' => ['game' => Game::class],
            'active_months' => [3, 4, 5, 6, 7, 8, 9, 10],
            'expected_games_per_day' => 10,
            'market_window_days' => 1,
            'weather_command' => 'mlb:sync-game-weather --days-back=0 --days-forward=7 --force',
            'injuries_command' => 'espn:sync-mlb-injuries',
            'futures_enabled' => true,
            'pipeline_order' => [
                ['label' => 'details before predictions', 'upstream' => ['espn:sync-mlb-game-details%'], 'downstream' => ['mlb:generate-predictions%'], 'recommended_action' => 'mlb:generate-predictions'],
                ['label' => 'weather before predictions', 'upstream' => ['mlb:sync-game-weather%'], 'downstream' => ['mlb:generate-predictions%'], 'recommended_action' => 'mlb:generate-predictions'],
                ['label' => 'odds before AI daily predictions', 'upstream' => ['mlb:sync-odds%'], 'downstream' => ['sports:ai-daily-predictions --sport=mlb%'], 'recommended_action' => 'sports:ai-daily-predictions --sport=mlb'],
            ],
        ],
        'nba' => [
            'tables' => ['teams' => 'nba_teams', 'games' => 'nba_games', 'team_stats' => 'nba_team_stats', 'player_stats' => 'nba_player_stats', 'plays' => 'nba_plays', 'injuries' => 'nba_player_injuries', 'player_props' => 'nba_player_props'],
            'models' => ['game' => App\Models\NBA\Game::class],
            'active_months' => [10, 11, 12, 1, 2, 3, 4, 5, 6],
            'expected_games_per_day' => 5,
            'market_window_days' => 1,
            'injuries_command' => 'espn:sync-nba-injuries',
            'futures_enabled' => true,
            'pipeline_order' => [
                ['label' => 'details before predictions', 'upstream' => ['espn:sync-nba-game-details%'], 'downstream' => ['nba:generate-predictions%'], 'recommended_action' => 'nba:generate-predictions'],
                ['label' => 'odds before player props', 'upstream' => ['nba:sync-odds%'], 'downstream' => ['nba:sync-player-props%'], 'recommended_action' => 'nba:sync-player-props'],
            ],
        ],
        'nfl' => [
            'tables' => ['teams' => 'nfl_teams', 'games' => 'nfl_games', 'team_stats' => 'nfl_team_stats', 'player_stats' => 'nfl_player_stats', 'plays' => 'nfl_plays', 'weather' => 'nfl_game_weather', 'injuries' => 'nfl_player_injuries', 'player_props' => 'nfl_player_props'],
            'models' => ['game' => App\Models\NFL\Game::class],
            'active_months' => [9, 10, 11, 12, 1, 2],
            'expected_games_per_day' => 1,
            'market_window_days' => 7,
            'weather_command' => 'nfl:sync-game-weather --days-back=0 --days-forward=7 --force',
            'injuries_command' => 'espn:sync-nfl-injuries',
            'futures_enabled' => true,
            'pipeline_order' => [
                ['label' => 'details before predictions', 'upstream' => ['espn:sync-nfl-game-details%'], 'downstream' => ['nfl:generate-predictions%'], 'recommended_action' => 'nfl:generate-predictions'],
                ['label' => 'weather before predictions', 'upstream' => ['nfl:sync-game-weather%'], 'downstream' => ['nfl:generate-predictions%'], 'recommended_action' => 'nfl:generate-predictions'],
                ['label' => 'odds before player props', 'upstream' => ['nfl:sync-odds%'], 'downstream' => ['nfl:sync-player-props%'], 'recommended_action' => 'nfl:sync-player-props'],
            ],
        ],
        'cbb' => [
            'tables' => ['teams' => 'cbb_teams', 'games' => 'cbb_games', 'team_stats' => 'cbb_team_stats', 'player_stats' => 'cbb_player_stats', 'plays' => 'cbb_plays', 'injuries' => 'cbb_player_injuries', 'player_props' => 'cbb_player_props'],
            'models' => ['game' => App\Models\CBB\Game::class],
            'active_months' => [11, 12, 1, 2, 3, 4],
            'expected_games_per_day' => 20,
            'market_window_days' => 1,
            'injuries_command' => 'espn:sync-cbb-injuries',
            'futures_enabled' => true,
            'pipeline_order' => [
                ['label' => 'details before predictions', 'upstream' => ['espn:sync-cbb-game-details%'], 'downstream' => ['cbb:generate-predictions%'], 'recommended_action' => 'cbb:generate-predictions'],
                ['label' => 'odds before player props', 'upstream' => ['cbb:sync-odds%'], 'downstream' => ['cbb:sync-player-props%'], 'recommended_action' => 'cbb:sync-player-props'],
            ],
        ],
        'cfb' => [
            'tables' => ['teams' => 'cfb_teams', 'games' => 'cfb_games', 'team_stats' => 'cfb_team_stats', 'player_stats' => 'cfb_player_stats', 'plays' => 'cfb_plays', 'injuries' => 'cfb_player_injuries'],
            'models' => ['game' => App\Models\CFB\Game::class],
            'active_months' => [8, 9, 10, 11, 12, 1],
            'expected_games_per_day' => 10,
            'market_window_days' => 7,
            'injuries_command' => 'espn:sync-cfb-injuries',
            'pipeline_order' => [
                ['label' => 'details before predictions', 'upstream' => ['espn:sync-cfb-game-details%'], 'downstream' => ['cfb:generate-predictions%'], 'recommended_action' => 'cfb:generate-predictions'],
            ],
        ],
        'wcbb' => [
            'tables' => ['teams' => 'wcbb_teams', 'games' => 'wcbb_games', 'team_stats' => 'wcbb_team_stats', 'player_stats' => 'wcbb_player_stats', 'plays' => 'wcbb_plays', 'injuries' => 'wcbb_player_injuries'],
            'models' => ['game' => App\Models\WCBB\Game::class],
            'active_months' => [11, 12, 1, 2, 3, 4],
            'expected_games_per_day' => 20,
            'market_window_days' => 1,
            'injuries_command' => 'espn:sync-wcbb-injuries',
            'pipeline_order' => [
                ['label' => 'details before predictions', 'upstream' => ['espn:sync-wcbb-game-details%'], 'downstream' => ['wcbb:generate-predictions%'], 'recommended_action' => 'wcbb:generate-predictions'],
            ],
        ],
        'wnba' => [
            'tables' => ['teams' => 'wnba_teams', 'games' => 'wnba_games', 'team_stats' => 'wnba_team_stats', 'player_stats' => 'wnba_player_stats', 'plays' => 'wnba_plays', 'injuries' => 'wnba_player_injuries', 'player_props' => 'wnba_player_props'],
            'models' => ['game' => App\Models\WNBA\Game::class],
            'active_months' => [5, 6, 7, 8, 9],
            'expected_games_per_day' => 2,
            'market_window_days' => 1,
            'injuries_command' => 'espn:sync-wnba-injuries',
            'pipeline_order' => [
                ['label' => 'details before predictions', 'upstream' => ['espn:sync-wnba-game-details%'], 'downstream' => ['wnba:generate-predictions%'], 'recommended_action' => 'wnba:generate-predictions'],
                ['label' => 'odds before player props', 'upstream' => ['wnba:sync-odds%'], 'downstream' => ['wnba:sync-player-props%'], 'recommended_action' => 'wnba:sync-player-props'],
            ],
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
        'current_day_game_data' => [
            'problem_warn_pct' => 0.05,
            'problem_fail_pct' => 0.20,
            'stale_after_hours' => 8,
            'final_stats_grace_hours' => 2,
        ],
        'upcoming_game_readiness' => [
            'problem_warn_pct' => 0.05,
            'problem_fail_pct' => 0.20,
            'stale_after_hours' => 12,
        ],
        'prediction_completeness' => [
            'missing_warn_pct' => 0.05,
            'missing_fail_pct' => 0.20,
        ],
        'past_scheduled_game_status' => [
            'lookback_days' => 7,
            'grace_hours' => 8,
        ],
        'odds_completeness' => [
            'problem_warn_pct' => 0.05,
            'problem_fail_pct' => 0.20,
            'missing_or_stale_fail_pct' => 0.50,
            'stale_after_hours' => 8,
            'soft_availability_hours' => 24,
            'expected_availability_hours' => 6,
        ],
        'weather_completeness' => [
            'problem_warn_pct' => 0.05,
            'problem_fail_pct' => 0.20,
            'stale_after_hours' => 8,
        ],
        'injury_freshness' => [
            'warning_after_hours' => 12,
            'failing_after_hours' => 24,
        ],
        'player_prop_freshness' => [
            'problem_warn_pct' => 0.05,
            'problem_fail_pct' => 0.20,
            'stale_after_hours' => 12,
            'soft_availability_hours' => 24,
            'expected_availability_hours' => 6,
        ],
        'futures_odds_freshness' => [
            'stale_after_hours' => 12,
            'minimum_rows' => 1,
        ],
        'finalized_data_completeness' => [
            'problem_warn_pct' => 0.05,
            'problem_fail_pct' => 0.20,
            'lookback_days' => 14,
            'grading_grace_hours' => 6,
        ],
    ],
];
