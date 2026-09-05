<?php

use App\Http\Controllers\Api\CBB\TournamentForecastController;
use App\Http\Controllers\Api\CFB\FpiRatingController;
use App\Http\Controllers\Api\MLB\SignalController as MlbSignalController;
use App\Http\Controllers\Api\NBA\PlayoffForecastController;
use App\Http\Controllers\Api\NBA\SignalController as NbaSignalController;
use App\Http\Controllers\Api\NFL\SignalController as NflSignalController;
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
    $requiresPredictionPermission = (bool) data_get(config('sports.domains'), "{$sport}.web.requires_prediction_permission", true);
    $sportAccessMiddleware = $requiresPredictionPermission ? ["permission:view-{$sport}-predictions"] : [];

    $registerIndexShowResource = function (string $resource, string $controller): void {
        Route::apiResource($resource, $controller)->only(['index', 'show']);
    };

    $registerAdditionalGetRoutes = function (array $routes): void {
        foreach ($routes as [$uri, $controller, $method, $name]) {
            Route::get($uri, [$controller, $method])->name($name);
        }
    };

    // Teams
    $registerIndexShowResource('teams', $controllers['team']);
    Route::middleware(['auth:sanctum', ...$sportAccessMiddleware])
        ->get('teams/{team}/trends', [$controllers['team'], 'trends'])
        ->name('teams.trends');
    if (($capabilities['depth_charts'] ?? false) === true) {
        Route::get('teams/{team}/depth-charts', [$controllers['depth_chart'], 'byTeam'])
            ->name('teams.depth-charts');
    }

    // Players
    $registerIndexShowResource('players', $controllers['player']);
    $registerAdditionalGetRoutes([
        ['teams/{team}/players', $controllers['player'], 'byTeam', 'teams.players'],
    ]);

    // Games
    $registerIndexShowResource('games', $controllers['game']);
    $registerAdditionalGetRoutes([
        ['games/{game}/plays', $controllers['play'], 'byGame', 'games.plays'],
        ['teams/{team}/games', $controllers['game'], 'byTeam', 'teams.games'],
        ['games/season/{season}', $controllers['game'], 'bySeason', 'games.by-season'],
        ['games/season/{season}/week/{week}', $controllers['game'], 'byWeek', 'games.by-week'],
    ]);
    if (($capabilities['depth_charts'] ?? false) === true) {
        Route::get('games/{game}/depth-charts', [$controllers['depth_chart'], 'byGame'])
            ->name('games.depth-charts');
    }

    // Plays
    $registerIndexShowResource('plays', $controllers['play']);

    // Sport-specific: Registered before apiResource to avoid route conflicts with {wildcard} params
    if (($capabilities['player_stats_leaderboard'] ?? false) === true) {
        Route::get('player-stats/leaderboard', [$controllers['player_stat'], 'leaderboard'])
            ->name('player-stats.leaderboard');
        Route::get('player-stats/available-seasons', [$controllers['player_stat'], 'availableSeasons'])
            ->name('player-stats.available-seasons');
    }

    if (($capabilities['team_stats_all_season_averages'] ?? false) === true) {
        Route::get('team-stats/season-averages', [$controllers['team_stat'], 'allSeasonAverages'])
            ->name('team-stats.season-averages');
    }

    if (($capabilities['team_stats_team_season_averages'] ?? false) === true) {
        Route::get('teams/{team}/stats/season-averages', [$controllers['team_stat'], 'seasonAverages'])
            ->name('teams.stats.season-averages');
    }

    // Player Stats
    $registerIndexShowResource('player-stats', $controllers['player_stat']);
    $registerAdditionalGetRoutes([
        ['games/{game}/player-stats', $controllers['player_stat'], 'byGame', 'games.player-stats'],
        ['players/{player}/stats', $controllers['player_stat'], 'byPlayer', 'players.stats'],
    ]);

    // Team Stats
    $registerIndexShowResource('team-stats', $controllers['team_stat']);
    $registerAdditionalGetRoutes([
        ['games/{game}/team-stats', $controllers['team_stat'], 'byGame', 'games.team-stats'],
        ['teams/{team}/stats', $controllers['team_stat'], 'byTeam', 'teams.stats'],
    ]);

    // ELO Ratings
    $registerIndexShowResource('elo-ratings', $controllers['elo']);
    $registerAdditionalGetRoutes([
        ['teams/{team}/elo-ratings', $controllers['elo'], 'byTeam', 'teams.elo-ratings'],
        ['elo-ratings/season/{season}', $controllers['elo'], 'bySeason', 'elo-ratings.by-season'],
    ]);

    // Protected endpoints (requires authentication for tier limits)
    Route::middleware(['auth:sanctum'])->group(function () use ($controllers, $sport, $capabilities, $namespace, $controllerNamespace, $sportAccessMiddleware) {
        Route::get('injuries', [InjuryController::class, 'index'])
            ->defaults('sport', $sport)
            ->middleware($sportAccessMiddleware)
            ->name('injuries.index');

        Route::get('debug/prediction-access', [PredictionAccessDebugController::class, 'show'])
            ->defaults('sport', $sport)
            ->name('debug.prediction-access');

        // Team Metrics
        Route::get('team-metrics/available-seasons', [$controllers['team_metric'], 'availableSeasons'])
            ->name('team-metrics.available-seasons');
        Route::apiResource('team-metrics', $controllers['team_metric'])->only(['index', 'show']);
        Route::get('teams/{team}/metrics', [$controllers['team_metric'], 'byTeam'])
            ->name('teams.metrics');

        // Predictions
        Route::middleware($sportAccessMiddleware)->group(function () use ($controllers): void {
            Route::get('predictions/available-dates', [$controllers['prediction'], 'availableDates'])
                ->name('predictions.available-dates');
            Route::get('predictions/available-seasons', [$controllers['prediction'], 'availableSeasons'])
                ->name('predictions.available-seasons');
            Route::apiResource('predictions', $controllers['prediction'])->only(['index', 'show']);
            Route::get('games/{game}/prediction', [$controllers['prediction'], 'byGame'])
                ->name('games.prediction');
        });

        if (($capabilities['tournament_forecasts'] ?? false) === true && $namespace === 'CBB') {
            Route::get('tournament-forecasts', [TournamentForecastController::class, 'index'])
                ->name('tournament-forecasts.index');
        }

        if (($capabilities['tournament_forecasts'] ?? false) === true && $namespace === 'WCBB') {
            Route::get('tournament-forecasts', [App\Http\Controllers\Api\WCBB\TournamentForecastController::class, 'index'])
                ->name('tournament-forecasts.index');
        }

        if (($capabilities['playoff_forecasts'] ?? false) === true && $namespace === 'NBA') {
            Route::get('playoff-forecasts', [PlayoffForecastController::class, 'index'])
                ->name('playoff-forecasts.index');
            Route::get('signals', [NbaSignalController::class, 'index'])
                ->middleware($sportAccessMiddleware)
                ->name('signals.index');
        }

        if (($capabilities['playoff_forecasts'] ?? false) === true && $namespace === 'MLB') {
            Route::get('playoff-forecasts', [App\Http\Controllers\Api\MLB\PlayoffForecastController::class, 'index'])
                ->name('playoff-forecasts.index');
            Route::get('signals', [MlbSignalController::class, 'index'])
                ->middleware($sportAccessMiddleware)
                ->name('signals.index');
        }

        if (($capabilities['playoff_forecasts'] ?? false) === true && $namespace === 'NFL') {
            Route::get('playoff-forecasts', [App\Http\Controllers\Api\NFL\PlayoffForecastController::class, 'index'])
                ->middleware($sportAccessMiddleware)
                ->name('playoff-forecasts.index');
            Route::get('signals', [NflSignalController::class, 'index'])
                ->middleware($sportAccessMiddleware)
                ->name('signals.index');
        }

        if ($namespace === 'MLB') {
            Route::get('bullpen-ratings', ["{$controllerNamespace}\\BullpenRatingController", 'index'])
                ->middleware($sportAccessMiddleware)
                ->name('bullpen-ratings.index');
            Route::get('teams/{team}/bullpen-ratings', ["{$controllerNamespace}\\BullpenRatingController", 'byTeam'])
                ->middleware($sportAccessMiddleware)
                ->name('teams.bullpen-ratings');
        }

        if ((bool) data_get(config('sports.domains'), "{$sport}.web.player_props", false) === true) {
            Route::get('player-props', [PlayerPropController::class, 'index'])
                ->defaults('sport', $sport)
                ->middleware($sportAccessMiddleware)
                ->name('player-props.index');
            Route::get('players/{player}/player-props', [PlayerPropController::class, 'byPlayer'])
                ->defaults('sport', $sport)
                ->middleware($sportAccessMiddleware)
                ->name('players.player-props');
        }

        if (($capabilities['player_futures'] ?? false) === true) {
            Route::get('player-futures', ["{$controllerNamespace}\\PlayerFutureController", 'index'])
                ->middleware($sportAccessMiddleware)
                ->name('player-futures.index');
        }

        if ($namespace === 'CFB') {
            Route::apiResource('fpi-ratings', FpiRatingController::class)
                ->only(['index', 'show']);
            Route::get('teams/{team}/fpi-ratings', [FpiRatingController::class, 'byTeam'])
                ->name('teams.fpi-ratings');
        }
    });
};
