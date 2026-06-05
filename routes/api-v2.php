<?php

use App\Http\Controllers\Api\V2\Admin\PayloadInspectorController;
use App\Http\Controllers\Api\V2\SportController;
use App\Http\Controllers\Api\V2\SportDepthChartController;
use App\Http\Controllers\Api\V2\SportForecastController;
use App\Http\Controllers\Api\V2\SportFuturesOddController;
use App\Http\Controllers\Api\V2\SportGameController;
use App\Http\Controllers\Api\V2\SportInjuryController;
use App\Http\Controllers\Api\V2\SportPlayerController;
use App\Http\Controllers\Api\V2\SportPlayerLeaderboardController;
use App\Http\Controllers\Api\V2\SportPlayerPropController;
use App\Http\Controllers\Api\V2\SportPredictionController;
use App\Http\Controllers\Api\V2\SportSignalController;
use App\Http\Controllers\Api\V2\SportStatController;
use App\Http\Controllers\Api\V2\SportTeamController;
use App\Http\Controllers\Api\V2\SportTeamMetricController;
use App\Http\Controllers\Api\V2\SportTeamTrendController;
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
            Route::get('/games/{game}/depth-charts', [SportDepthChartController::class, 'gameShow'])->name('games.depth-charts.show');
            Route::get('/games/{game}/prediction', [SportPredictionController::class, 'gamePrediction'])->name('games.prediction.show');
            Route::get('/games/{game}/player-props', [SportPlayerPropController::class, 'gameIndex'])->name('games.player-props.index');

            Route::get('/teams', [SportTeamController::class, 'index'])->name('teams.index');
            Route::get('/teams/{team}', [SportTeamController::class, 'show'])->name('teams.show');
            Route::get('/teams/{team}/futures', [SportFuturesOddController::class, 'teamIndex'])->name('teams.futures.index');
            Route::get('/teams/{team}/games', [SportGameController::class, 'teamIndex'])->name('teams.games.index');
            Route::get('/teams/{team}/depth-charts', [SportDepthChartController::class, 'teamShow'])->name('teams.depth-charts.show');
            Route::get('/teams/{team}/metrics', [SportTeamMetricController::class, 'teamShow'])->name('teams.metrics.show');
            Route::get('/teams/{team}/players', [SportPlayerController::class, 'teamIndex'])->name('teams.players.index');
            Route::get('/teams/{team}/trends', [SportTeamTrendController::class, 'show'])->name('teams.trends.show');

            Route::get('/players', [SportPlayerController::class, 'index'])->name('players.index');
            Route::get('/players/{player}', [SportPlayerController::class, 'show'])->name('players.show');
            Route::get('/players/{player}/player-props', [SportPlayerPropController::class, 'playerIndex'])->name('players.player-props.index');

            Route::get('/player-props', [SportPlayerPropController::class, 'index'])->name('player-props.index');
            Route::get('/player-props/board', [SportPlayerPropController::class, 'board'])->name('player-props.board');
            Route::get('/forecasts', [SportForecastController::class, 'index'])->name('forecasts.index');
            Route::get('/injuries', [SportInjuryController::class, 'index'])->name('injuries.index');
            Route::get('/signals', [SportSignalController::class, 'index'])->name('signals.index');

            Route::get('/predictions', [SportPredictionController::class, 'index'])->name('predictions.index');
            Route::get('/predictions/available-seasons', [SportPredictionController::class, 'availableSeasons'])->name('predictions.available-seasons');
            Route::get('/predictions/available-dates', [SportPredictionController::class, 'availableDates'])->name('predictions.available-dates');
            Route::get('/predictions/{prediction}', [SportPredictionController::class, 'show'])->name('predictions.show');

            Route::get('/markets/futures', [SportFuturesOddController::class, 'index'])->name('markets.futures.index');
            Route::get('/markets/player-props', [SportPlayerPropController::class, 'index'])->name('markets.player-props.index');

            Route::get('/leaderboards/players/available-seasons', [SportPlayerLeaderboardController::class, 'availableSeasons'])->name('leaderboards.players.available-seasons');
            Route::get('/leaderboards/players', [SportPlayerLeaderboardController::class, 'index'])->name('leaderboards.players.index');

            Route::get('/metrics/teams/available-seasons', [SportTeamMetricController::class, 'availableSeasons'])->name('metrics.teams.available-seasons');
            Route::get('/metrics/teams', [SportTeamMetricController::class, 'index'])->name('metrics.teams.index');

            Route::get('/stats/player/available-seasons', [SportStatController::class, 'playerAvailableSeasons'])->name('stats.player.available-seasons');
            Route::get('/stats/player/available-dates', [SportStatController::class, 'playerAvailableDates'])->name('stats.player.available-dates');
            Route::get('/stats/player', [SportStatController::class, 'playerIndex'])->name('stats.player.index');
            Route::get('/stats/team/available-seasons', [SportStatController::class, 'teamAvailableSeasons'])->name('stats.team.available-seasons');
            Route::get('/stats/team/available-dates', [SportStatController::class, 'teamAvailableDates'])->name('stats.team.available-dates');
            Route::get('/stats/team', [SportStatController::class, 'teamIndex'])->name('stats.team.index');
        });
});
