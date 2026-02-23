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

    // Teams
    Route::apiResource('teams', "{$controllerNamespace}\\TeamController");
    Route::middleware('web')->get('teams/{team}/trends', ["{$controllerNamespace}\\TeamController", 'trends']);

    // Players
    Route::apiResource('players', "{$controllerNamespace}\\PlayerController");
    Route::get('teams/{team}/players', ["{$controllerNamespace}\\PlayerController", 'byTeam']);

    // Games
    Route::apiResource('games', "{$controllerNamespace}\\GameController");
    Route::get('games/{game}/plays', ["{$controllerNamespace}\\PlayController", 'byGame']);
    Route::get('teams/{team}/games', ["{$controllerNamespace}\\GameController", 'byTeam']);
    Route::get('games/season/{season}', ["{$controllerNamespace}\\GameController", 'bySeason']);
    Route::get('games/season/{season}/week/{week}', ["{$controllerNamespace}\\GameController", 'byWeek']);

    // Plays
    Route::apiResource('plays', "{$controllerNamespace}\\PlayController")->only(['index', 'show']);

    // Player Stats
    Route::apiResource('player-stats', "{$controllerNamespace}\\PlayerStatController")->only(['index', 'show']);
    Route::get('games/{game}/player-stats', ["{$controllerNamespace}\\PlayerStatController", 'byGame']);
    Route::get('players/{player}/stats', ["{$controllerNamespace}\\PlayerStatController", 'byPlayer']);

    // NBA-specific: Season averages for team stats (registered before apiResource to avoid route conflicts)
    if ($sport === 'nba') {
        Route::get('team-stats/season-averages', ["{$controllerNamespace}\\TeamStatController", 'allSeasonAverages']);
        Route::get('teams/{team}/stats/season-averages', ["{$controllerNamespace}\\TeamStatController", 'seasonAverages']);
    }

    // Team Stats
    Route::apiResource('team-stats', "{$controllerNamespace}\\TeamStatController")->only(['index', 'show']);
    Route::get('games/{game}/team-stats', ["{$controllerNamespace}\\TeamStatController", 'byGame']);
    Route::get('teams/{team}/stats', ["{$controllerNamespace}\\TeamStatController", 'byTeam']);

    // ELO Ratings
    Route::apiResource('elo-ratings', "{$controllerNamespace}\\EloRatingController")->only(['index', 'show']);
    Route::get('teams/{team}/elo-ratings', ["{$controllerNamespace}\\EloRatingController", 'byTeam']);
Route::get('elo-ratings/season/{season}', ["{$controllerNamespace}\\EloRatingController", 'bySeason']);

    // Team Metrics (requires authentication for tier limits)
    Route::middleware(['web', 'auth'])->group(function () use ($controllerNamespace) {
        Route::apiResource('team-metrics', "{$controllerNamespace}\\TeamMetricController")->only(['index', 'show']);
        Route::get('teams/{team}/metrics', ["{$controllerNamespace}\\TeamMetricController", 'byTeam']);
    });

    // Predictions (requires authentication for tier limits)
    Route::middleware(['web', 'auth'])->group(function () use ($controllerNamespace) {
        Route::get('predictions/available-dates', ["{$controllerNamespace}\\PredictionController", 'availableDates']);
        Route::apiResource('predictions', "{$controllerNamespace}\\PredictionController")->only(['index', 'show']);
        Route::get('games/{game}/prediction', ["{$controllerNamespace}\\PredictionController", 'byGame']);
    });
};
