<?php

return [
    'server_timing_enabled' => (bool) env('PERFORMANCE_SERVER_TIMING_ENABLED', true),
    'slow_request_ms' => (float) env('PERFORMANCE_SLOW_REQUEST_MS', 750),
    'slow_request_logging_enabled' => (bool) env('PERFORMANCE_SLOW_REQUEST_LOGGING_ENABLED', true),
    'player_leaderboard_cache_seconds' => (int) env('PERFORMANCE_PLAYER_LEADERBOARD_CACHE_SECONDS', 300),
    'player_stat_seasons_cache_seconds' => (int) env('PERFORMANCE_PLAYER_STAT_SEASONS_CACHE_SECONDS', 900),
    'player_props_cache_seconds' => (int) env('PERFORMANCE_PLAYER_PROPS_CACHE_SECONDS', 60),
];
