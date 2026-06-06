<?php

return [
    'business_timezone' => env('SPORTS_BUSINESS_TIMEZONE', env('APP_TIMEZONE', 'UTC')),

    /*
    |--------------------------------------------------------------------------
    | Sport Domain Map
    |--------------------------------------------------------------------------
    |
    | Centralized domain-first configuration for sport APIs. Keep routing
    | capabilities here so route registration stays declarative.
    |
    */
    'domains' => [
        'nfl' => [
            'namespace' => 'NFL',
            'capabilities' => [
                'player_stats_leaderboard' => true,
                'depth_charts' => true,
                'player_futures' => true,
                'playoff_forecasts' => true,
            ],
            'web' => [
                'predictions_page' => 'NFL/Predictions',
                'requires_prediction_permission' => false,
                'player_props' => true,
                'pages' => [
                    'team-metrics' => 'NFL/TeamMetrics',
                    'player-stats' => 'NFL/PlayerStats',
                    'injuries' => 'NFL/Injuries',
                    'futures' => 'NFL/Futures',
                ],
                'details' => [
                    'team' => true,
                    'game' => true,
                    'player' => true,
                ],
            ],
        ],
        'cfb' => [
            'namespace' => 'CFB',
            'capabilities' => [
                'player_stats_leaderboard' => true,
            ],
            'web' => [
                'predictions_page' => 'CFB/Predictions',
                'requires_prediction_permission' => false,
                'player_props' => false,
                'pages' => [
                    'player-stats' => 'CFB/PlayerStats',
                    'injuries' => 'CFB/Injuries',
                ],
                'details' => [
                    'team' => false,
                    'game' => true,
                    'player' => true,
                ],
            ],
        ],
        'cbb' => [
            'namespace' => 'CBB',
            'capabilities' => [
                'player_stats_leaderboard' => true,
                'team_stats_team_season_averages' => true,
                'tournament_forecasts' => true,
            ],
            'web' => [
                'predictions_page' => 'CBB/Predictions',
                'requires_prediction_permission' => false,
                'player_props' => true,
                'pages' => [
                    'team-metrics' => 'CBB/TeamMetrics',
                    'player-stats' => 'CBB/PlayerStats',
                    'tournament-forecast' => 'CBB/TournamentForecast',
                    'injuries' => 'CBB/Injuries',
                ],
                'details' => [
                    'team' => true,
                    'game' => true,
                    'player' => true,
                ],
            ],
        ],
        'wcbb' => [
            'namespace' => 'WCBB',
            'capabilities' => [
                'team_stats_all_season_averages' => true,
                'team_stats_team_season_averages' => true,
                'tournament_forecasts' => true,
            ],
            'web' => [
                'predictions_page' => 'WCBB/Predictions',
                'requires_prediction_permission' => false,
                'player_props' => false,
                'pages' => [
                    'team-metrics' => 'WCBB/TeamMetrics',
                    'tournament-forecast' => 'WCBB/TournamentForecast',
                    'injuries' => 'WCBB/Injuries',
                ],
                'details' => [
                    'team' => true,
                    'game' => true,
                    'player' => false,
                ],
            ],
        ],
        'nba' => [
            'namespace' => 'NBA',
            'capabilities' => [
                'player_stats_leaderboard' => true,
                'team_stats_all_season_averages' => true,
                'team_stats_team_season_averages' => true,
                'playoff_forecasts' => true,
                'depth_charts' => true,
            ],
            'web' => [
                'predictions_page' => 'NBA/Predictions',
                'requires_prediction_permission' => false,
                'player_props' => true,
                'pages' => [
                    'team-metrics' => 'NBA/TeamMetrics',
                    'injuries' => 'NBA/Injuries',
                    'player-stats' => 'NBA/PlayerStats',
                    'futures' => 'NBA/Futures',
                ],
                'details' => [
                    'team' => true,
                    'game' => true,
                    'player' => true,
                ],
            ],
        ],
        'wnba' => [
            'namespace' => 'WNBA',
            'capabilities' => [
                'player_stats_leaderboard' => true,
            ],
            'web' => [
                'predictions_page' => 'WNBA/Predictions',
                'requires_prediction_permission' => false,
                'player_props' => true,
                'pages' => [
                    'team-metrics' => 'WNBA/TeamMetrics',
                    'injuries' => 'WNBA/Injuries',
                ],
                'details' => [
                    'team' => true,
                    'game' => true,
                    'player' => false,
                ],
            ],
        ],
        'mlb' => [
            'namespace' => 'MLB',
            'capabilities' => [
                'player_stats_leaderboard' => true,
                'team_stats_team_season_averages' => true,
                'playoff_forecasts' => true,
                'depth_charts' => true,
            ],
            'web' => [
                'predictions_page' => 'MLB/Predictions',
                'requires_prediction_permission' => false,
                'player_props' => true,
                'pages' => [
                    'team-metrics' => 'MLB/TeamMetrics',
                    'injuries' => 'MLB/Injuries',
                    'player-stats' => 'MLB/PlayerStats',
                    'futures' => 'MLB/Futures',
                ],
                'details' => [
                    'team' => true,
                    'game' => true,
                    'player' => true,
                ],
            ],
        ],
    ],
];
