<?php

return [
    'ttl' => [
        'dashboard_seconds' => (int) env('SPORTS_CACHE_DASHBOARD_SECONDS', 20),
        'live_scoreboard_seconds' => (int) env('SPORTS_CACHE_LIVE_SCOREBOARD_SECONDS', 10),
        'team_games_by_team_seconds' => (int) env('SPORTS_CACHE_TEAM_GAMES_BY_TEAM_SECONDS', 60),
        'team_metrics_index_seconds' => (int) env('SPORTS_CACHE_TEAM_METRICS_INDEX_SECONDS', 120),
        'team_metrics_by_team_seconds' => (int) env('SPORTS_CACHE_TEAM_METRICS_BY_TEAM_SECONDS', 120),
        'team_metrics_available_seasons_seconds' => (int) env('SPORTS_CACHE_TEAM_METRICS_AVAILABLE_SEASONS_SECONDS', 600),
        'team_stats_index_seconds' => (int) env('SPORTS_CACHE_TEAM_STATS_INDEX_SECONDS', 120),
        'team_stats_by_game_seconds' => (int) env('SPORTS_CACHE_TEAM_STATS_BY_GAME_SECONDS', 120),
        'team_stats_by_team_seconds' => (int) env('SPORTS_CACHE_TEAM_STATS_BY_TEAM_SECONDS', 120),
        'team_stats_season_averages_seconds' => (int) env('SPORTS_CACHE_TEAM_STATS_SEASON_AVERAGES_SECONDS', 120),
        'team_stats_all_season_averages_seconds' => (int) env('SPORTS_CACHE_TEAM_STATS_ALL_SEASON_AVERAGES_SECONDS', 120),
        'team_trends_seconds' => (int) env('SPORTS_CACHE_TEAM_TRENDS_SECONDS', 120),
        'predictions_index_seconds' => (int) env('SPORTS_CACHE_PREDICTIONS_INDEX_SECONDS', 60),
        'predictions_by_game_seconds' => (int) env('SPORTS_CACHE_PREDICTIONS_BY_GAME_SECONDS', 30),
        'predictions_available_dates_seconds' => (int) env('SPORTS_CACHE_PREDICTIONS_AVAILABLE_DATES_SECONDS', 300),
        'predictions_available_seasons_seconds' => (int) env('SPORTS_CACHE_PREDICTIONS_AVAILABLE_SEASONS_SECONDS', 600),
        'player_props_page_seconds' => (int) env('SPORTS_CACHE_PLAYER_PROPS_PAGE_SECONDS', 60),
        'futures_forecasts_seconds' => (int) env('SPORTS_CACHE_FUTURES_FORECASTS_SECONDS', 120),
    ],
];
