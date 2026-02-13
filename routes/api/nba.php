<?php

use App\Http\Controllers\Api\NBA\EloRatingController;
use App\Http\Controllers\Api\NBA\GameController;
use App\Http\Controllers\Api\NBA\PlayController;
use App\Http\Controllers\Api\NBA\PlayerController;
use App\Http\Controllers\Api\NBA\PlayerStatController;
use App\Http\Controllers\Api\NBA\PredictionController;
use App\Http\Controllers\Api\NBA\TeamController;
use App\Http\Controllers\Api\NBA\TeamMetricController;
use App\Http\Controllers\Api\NBA\TeamStatController;
use Illuminate\Support\Facades\Route;

// Teams
Route::apiResource('teams', TeamController::class);
Route::middleware('web')->get('teams/{team}/trends', [TeamController::class, 'trends']);

// Players
Route::apiResource('players', PlayerController::class);
Route::get('teams/{team}/players', [PlayerController::class, 'byTeam']);

// Games
Route::apiResource('games', GameController::class);
Route::get('games/{game}/plays', [PlayController::class, 'byGame']);
Route::get('teams/{team}/games', [GameController::class, 'byTeam']);
Route::get('games/season/{season}', [GameController::class, 'bySeason']);
Route::get('games/season/{season}/week/{week}', [GameController::class, 'byWeek']);

// Plays
Route::apiResource('plays', PlayController::class)->only(['index', 'show']);

// Player Stats
Route::apiResource('player-stats', PlayerStatController::class)->only(['index', 'show']);
Route::get('games/{game}/player-stats', [PlayerStatController::class, 'byGame']);
Route::get('players/{player}/stats', [PlayerStatController::class, 'byPlayer']);

// Team Stats
Route::apiResource('team-stats', TeamStatController::class)->only(['index', 'show']);
Route::get('games/{game}/team-stats', [TeamStatController::class, 'byGame']);
Route::get('teams/{team}/stats', [TeamStatController::class, 'byTeam']);
Route::get('teams/{team}/stats/season-averages', [TeamStatController::class, 'seasonAverages']);

// ELO Ratings
Route::apiResource('elo-ratings', EloRatingController::class)->only(['index', 'show']);
Route::get('teams/{team}/elo-ratings', [EloRatingController::class, 'byTeam']);
Route::get('elo-ratings/season/{season}', [EloRatingController::class, 'bySeason']);

// Team Metrics (requires authentication for tier limits)
Route::middleware(['web', 'auth'])->group(function () {
    Route::apiResource('team-metrics', TeamMetricController::class)->only(['index', 'show']);
    Route::get('teams/{team}/metrics', [TeamMetricController::class, 'byTeam']);
});

// Predictions (requires authentication for tier limits)
Route::middleware(['web', 'auth'])->group(function () {
    Route::apiResource('predictions', PredictionController::class)->only(['index', 'show']);
    Route::get('games/{game}/prediction', [PredictionController::class, 'byGame']);
});
