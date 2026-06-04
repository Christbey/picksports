<?php

use App\Http\Controllers\Api\V2\Admin\PayloadInspectorController;
use App\Http\Controllers\Api\V2\SportController;
use App\Http\Controllers\Api\V2\SportGameController;
use App\Http\Controllers\Api\V2\SportPlayerController;
use App\Http\Controllers\Api\V2\SportPredictionController;
use App\Http\Controllers\Api\V2\SportStatController;
use App\Http\Controllers\Api\V2\SportTeamController;
use Illuminate\Support\Facades\Route;

Route::prefix('v2')->name('v2.')->group(function (): void {
    Route::get('/sports', [SportController::class, 'index'])->name('sports.index');
    Route::get('/sports/{sport}', [SportController::class, 'show'])->name('sports.show');

    Route::middleware(['auth:sanctum', 'admin'])
        ->prefix('/admin')
        ->name('admin.')
        ->group(function (): void {
            Route::get('/payload-inspector', PayloadInspectorController::class)->name('payload-inspector');
        });

    Route::middleware(['auth:sanctum', 'v2.sport-api-access'])
        ->prefix('/sports/{sport}')
        ->name('sports.')
        ->group(function (): void {
            Route::get('/games', [SportGameController::class, 'index'])->name('games.index');
            Route::get('/games/{game}', [SportGameController::class, 'show'])->name('games.show');
            Route::get('/games/{game}/prediction', [SportPredictionController::class, 'gamePrediction'])->name('games.prediction.show');

            Route::get('/teams', [SportTeamController::class, 'index'])->name('teams.index');
            Route::get('/teams/{team}', [SportTeamController::class, 'show'])->name('teams.show');
            Route::get('/teams/{team}/players', [SportPlayerController::class, 'teamIndex'])->name('teams.players.index');

            Route::get('/players', [SportPlayerController::class, 'index'])->name('players.index');
            Route::get('/players/{player}', [SportPlayerController::class, 'show'])->name('players.show');

            Route::get('/predictions', [SportPredictionController::class, 'index'])->name('predictions.index');
            Route::get('/predictions/{prediction}', [SportPredictionController::class, 'show'])->name('predictions.show');

            Route::get('/stats/player', [SportStatController::class, 'playerIndex'])->name('stats.player.index');
            Route::get('/stats/team', [SportStatController::class, 'teamIndex'])->name('stats.team.index');
        });
});
