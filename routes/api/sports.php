<?php

use Illuminate\Support\Facades\Route;

/**
 * Register generic API routes for a sport
 *
 * @param  string  $sport  Sport slug (e.g., 'nba', 'nfl')
 * @param  string  $namespace  Controller namespace (e.g., 'NBA', 'NFL')
 * @return void
 */
return function (string $sport, string $namespace) {
    $controllerNamespace = "App\\Http\\Controllers\\Api\\{$namespace}";
    $controllers = [
        'team' => "{$controllerNamespace}\\TeamController",
        'player' => "{$controllerNamespace}\\PlayerController",
        'game' => "{$controllerNamespace}\\GameController",
        'play' => "{$controllerNamespace}\\PlayController",
        'player_stat' => "{$controllerNamespace}\\PlayerStatController",
        'team_stat' => "{$controllerNamespace}\\TeamStatController",
        'elo' => "{$controllerNamespace}\\EloRatingController",
        'team_metric' => "{$controllerNamespace}\\TeamMetricController",
        'prediction' => "{$controllerNamespace}\\PredictionController",
    ];
    $sportCapabilities = [
        'nba' => [
            'player_stats_leaderboard' => true,
            'team_stats_all_season_averages' => true,
            'team_stats_team_season_averages' => true,
        ],
        'cbb' => [
            'player_stats_leaderboard' => true,
            'team_stats_team_season_averages' => true,
        ],
        'mlb' => [
            'team_stats_team_season_averages' => true,
        ],
    ];
    $capabilities = $sportCapabilities[$sport] ?? [];

    $registerIndexShowResource = function (string $resource, string $controller): void {
        Route::apiResource($resource, $controller)->only(['index', 'show']);
    };

    $registerAdditionalGetRoutes = function (array $routes): void {
        foreach ($routes as [$uri, $controller, $method]) {
            Route::get($uri, [$controller, $method]);
        }
    };

    // Teams
    $registerIndexShowResource('teams', $controllers['team']);
    $registerAdditionalGetRoutes([
        ['teams/{team}/trends', $controllers['team'], 'trends'],
    ]);

    // Players
    $registerIndexShowResource('players', $controllers['player']);
    $registerAdditionalGetRoutes([
        ['teams/{team}/players', $controllers['player'], 'byTeam'],
    ]);

    // Games
    $registerIndexShowResource('games', $controllers['game']);
    $registerAdditionalGetRoutes([
        ['games/{game}/plays', $controllers['play'], 'byGame'],
        ['teams/{team}/games', $controllers['game'], 'byTeam'],
        ['games/season/{season}', $controllers['game'], 'bySeason'],
        ['games/season/{season}/week/{week}', $controllers['game'], 'byWeek'],
    ]);

    // Plays
    $registerIndexShowResource('plays', $controllers['play']);

    // Sport-specific: Registered before apiResource to avoid route conflicts with {wildcard} params
    if (($capabilities['player_stats_leaderboard'] ?? false) === true) {
        Route::get('player-stats/leaderboard', [$controllers['player_stat'], 'leaderboard']);
    }

    if (($capabilities['team_stats_all_season_averages'] ?? false) === true) {
        Route::get('team-stats/season-averages', [$controllers['team_stat'], 'allSeasonAverages']);
    }

    if (($capabilities['team_stats_team_season_averages'] ?? false) === true) {
        Route::get('teams/{team}/stats/season-averages', [$controllers['team_stat'], 'seasonAverages']);
    }

    // Player Stats
    $registerIndexShowResource('player-stats', $controllers['player_stat']);
    $registerAdditionalGetRoutes([
        ['games/{game}/player-stats', $controllers['player_stat'], 'byGame'],
        ['players/{player}/stats', $controllers['player_stat'], 'byPlayer'],
    ]);

    // Team Stats
    $registerIndexShowResource('team-stats', $controllers['team_stat']);
    $registerAdditionalGetRoutes([
        ['games/{game}/team-stats', $controllers['team_stat'], 'byGame'],
        ['teams/{team}/stats', $controllers['team_stat'], 'byTeam'],
    ]);

    // ELO Ratings
    $registerIndexShowResource('elo-ratings', $controllers['elo']);
    $registerAdditionalGetRoutes([
        ['teams/{team}/elo-ratings', $controllers['elo'], 'byTeam'],
        ['elo-ratings/season/{season}', $controllers['elo'], 'bySeason'],
    ]);

    // Protected endpoints (requires authentication for tier limits)
    Route::middleware(['auth:sanctum'])->group(function () use ($controllers) {
        // Team Metrics
        Route::apiResource('team-metrics', $controllers['team_metric'])->only(['index', 'show']);
        Route::get('teams/{team}/metrics', [$controllers['team_metric'], 'byTeam']);

        // Predictions
        Route::get('predictions/available-dates', [$controllers['prediction'], 'availableDates']);
        Route::apiResource('predictions', $controllers['prediction'])->only(['index', 'show']);
        Route::get('games/{game}/prediction', [$controllers['prediction'], 'byGame']);
    });
};
