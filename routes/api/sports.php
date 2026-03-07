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
    $capabilities = (array) data_get(config('sports.domains'), "{$sport}.capabilities", []);

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
    Route::middleware(['auth:sanctum', "permission:view-{$sport}-predictions"])
        ->get('teams/{team}/trends', [$controllers['team'], 'trends']);

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
        Route::get('player-stats/available-seasons', [$controllers['player_stat'], 'availableSeasons']);
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
    Route::middleware(['auth:sanctum'])->group(function () use ($controllers, $sport, $capabilities, $namespace) {
        Route::get('injuries', [\App\Http\Controllers\Api\Sports\InjuryController::class, 'index'])
            ->defaults('sport', $sport)
            ->middleware(["permission:view-{$sport}-predictions"]);

        Route::get('debug/prediction-access', [\App\Http\Controllers\Api\Sports\PredictionAccessDebugController::class, 'show'])
            ->defaults('sport', $sport);

        // Team Metrics
        Route::get('team-metrics/available-seasons', [$controllers['team_metric'], 'availableSeasons']);
        Route::apiResource('team-metrics', $controllers['team_metric'])->only(['index', 'show']);
        Route::get('teams/{team}/metrics', [$controllers['team_metric'], 'byTeam']);

        // Predictions
        Route::get('predictions/available-dates', [$controllers['prediction'], 'availableDates']);
        Route::get('predictions/available-seasons', [$controllers['prediction'], 'availableSeasons']);
        Route::apiResource('predictions', $controllers['prediction'])->only(['index', 'show']);
        Route::get('games/{game}/prediction', [$controllers['prediction'], 'byGame']);

        if (($capabilities['tournament_forecasts'] ?? false) === true && $namespace === 'CBB') {
            Route::get('tournament-forecasts', [\App\Http\Controllers\Api\CBB\TournamentForecastController::class, 'index']);
        }

        if (($capabilities['tournament_forecasts'] ?? false) === true && $namespace === 'WCBB') {
            Route::get('tournament-forecasts', [\App\Http\Controllers\Api\WCBB\TournamentForecastController::class, 'index']);
        }

        if (($capabilities['playoff_forecasts'] ?? false) === true && $namespace === 'NBA') {
            Route::get('playoff-forecasts', [\App\Http\Controllers\Api\NBA\PlayoffForecastController::class, 'index']);
        }

        if (($capabilities['playoff_forecasts'] ?? false) === true && $namespace === 'MLB') {
            Route::get('playoff-forecasts', [\App\Http\Controllers\Api\MLB\PlayoffForecastController::class, 'index']);
        }
    });
};
