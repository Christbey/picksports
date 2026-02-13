<?php

use App\Http\Controllers\Api\CFB\EloRatingController;
use App\Http\Controllers\Api\CFB\FpiRatingController;
use App\Http\Controllers\Api\CFB\GameController;
use App\Http\Controllers\Api\CFB\PlayController;
use App\Http\Controllers\Api\CFB\PlayerController;
use App\Http\Controllers\Api\CFB\PlayerStatController;
use App\Http\Controllers\Api\CFB\PredictionController;
use App\Http\Controllers\Api\CFB\TeamController;
use App\Http\Controllers\Api\CFB\TeamStatController;
use Illuminate\Support\Facades\Route;

// Teams
Route::apiResource('teams', TeamController::class);

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

// ELO Ratings
Route::apiResource('elo-ratings', EloRatingController::class)->only(['index', 'show']);
Route::get('teams/{team}/elo-ratings', [EloRatingController::class, 'byTeam']);
Route::get('elo-ratings/season/{season}', [EloRatingController::class, 'bySeason']);

// FPI Ratings
Route::apiResource('fpi-ratings', FpiRatingController::class)->only(['index', 'show']);
Route::get('teams/{team}/fpi-ratings', [FpiRatingController::class, 'byTeam']);

// Predictions (requires authentication for tier limits)
Route::middleware(['web', 'auth'])->group(function () {
    Route::apiResource('predictions', PredictionController::class)->only(['index', 'show']);
    Route::get('games/{game}/prediction', [PredictionController::class, 'byGame']);
});
