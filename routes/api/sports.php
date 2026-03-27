<?php

use App\Http\Controllers\Api\CBB\TournamentForecastController;
use App\Http\Controllers\Api\CFB\FpiRatingController;
use App\Http\Controllers\Api\NBA\PlayoffForecastController;
use App\Http\Controllers\Api\Sports\InjuryController;
use App\Http\Controllers\Api\Sports\PlayerPropController;
use App\Http\Controllers\Api\Sports\PredictionAccessDebugController;
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
        'depth_chart' => "{$controllerNamespace}\\DepthChartController",
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
    if (($capabilities['depth_charts'] ?? false) === true) {
        Route::get('teams/{team}/depth-charts', [$controllers['depth_chart'], 'byTeam']);
    }

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
    if (($capabilities['depth_charts'] ?? false) === true) {
        Route::get('games/{game}/depth-charts', [$controllers['depth_chart'], 'byGame']);
    }

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
        Route::get('injuries', [InjuryController::class, 'index'])
            ->defaults('sport', $sport)
            ->middleware(["permission:view-{$sport}-predictions"]);

        Route::get('debug/prediction-access', [PredictionAccessDebugController::class, 'show'])
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
            Route::get('tournament-forecasts', [TournamentForecastController::class, 'index']);
        }

        if (($capabilities['tournament_forecasts'] ?? false) === true && $namespace === 'WCBB') {
            Route::get('tournament-forecasts', [App\Http\Controllers\Api\WCBB\TournamentForecastController::class, 'index']);
        }

        if (($capabilities['playoff_forecasts'] ?? false) === true && $namespace === 'NBA') {
            Route::get('playoff-forecasts', [PlayoffForecastController::class, 'index']);
        }

        if (($capabilities['playoff_forecasts'] ?? false) === true && $namespace === 'MLB') {
            Route::get('playoff-forecasts', [App\Http\Controllers\Api\MLB\PlayoffForecastController::class, 'index']);
        }

        if ((bool) data_get(config('sports.domains'), "{$sport}.web.player_props", false) === true) {
            Route::get('player-props', [PlayerPropController::class, 'index'])
                ->defaults('sport', $sport)
                ->middleware(["permission:view-{$sport}-predictions"]);
            Route::get('players/{player}/player-props', [PlayerPropController::class, 'byPlayer'])
                ->defaults('sport', $sport)
                ->middleware(["permission:view-{$sport}-predictions"]);
        }

        if ($namespace === 'CFB') {
            Route::apiResource('fpi-ratings', FpiRatingController::class)
                ->only(['index', 'show']);
            Route::get('teams/{team}/fpi-ratings', [FpiRatingController::class, 'byTeam']);
        }
    });
};
