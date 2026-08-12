<?php

use App\Http\Controllers\AlertPreferenceController;
use App\Http\Controllers\Api\Auth\PasskeyTokenAuthController;
use App\Http\Controllers\Api\Auth\TokenAuthController;
use App\Http\Controllers\Api\CBB\BracketController as CbbBracketController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\V2\Admin\PayloadInspectorController;
use App\Http\Controllers\Api\V2\LiveScoreboardController;
use App\Http\Controllers\Api\V2\MlbDailyPickController;
use App\Http\Controllers\Api\V2\SportController;
use App\Http\Controllers\Api\V2\SportDepthChartController;
use App\Http\Controllers\Api\V2\SportForecastController;
use App\Http\Controllers\Api\V2\SportFuturesOddController;
use App\Http\Controllers\Api\V2\SportGameController;
use App\Http\Controllers\Api\V2\SportGamePageController;
use App\Http\Controllers\Api\V2\SportGameTrendController;
use App\Http\Controllers\Api\V2\SportInjuryController;
use App\Http\Controllers\Api\V2\SportPlayerController;
use App\Http\Controllers\Api\V2\SportPlayerLeaderboardController;
use App\Http\Controllers\Api\V2\SportPlayerPropController;
use App\Http\Controllers\Api\V2\SportPredictionController;
use App\Http\Controllers\Api\V2\SportSignalController;
use App\Http\Controllers\Api\V2\SportStatController;
use App\Http\Controllers\Api\V2\SportTeamController;
use App\Http\Controllers\Api\V2\SportTeamMetricController;
use App\Http\Controllers\Api\V2\SportTeamStatAverageController;
use App\Http\Controllers\Api\V2\SportTeamTrendController;
use App\Http\Controllers\BetTrackerController;
use Illuminate\Support\Facades\Route;

Route::prefix('v2')->name('v2.')->group(function (): void {
    Route::get('/sports', [SportController::class, 'index'])->name('sports.index');
    Route::get('/sports/{sport}', [SportController::class, 'show'])->name('sports.show');

    Route::prefix('/auth')
        ->name('auth.')
        ->group(function (): void {
            Route::post('/login', [TokenAuthController::class, 'login'])
                ->middleware('throttle:10,1')
                ->name('login');
            Route::post('/passkeys/options', [PasskeyTokenAuthController::class, 'options'])
                ->middleware('throttle:20,1')
                ->name('passkeys.createOptions');
            Route::post('/passkeys/verify', [PasskeyTokenAuthController::class, 'verify'])
                ->middleware('throttle:10,1')
                ->name('passkeys.verify');

            Route::middleware('auth:sanctum')->group(function (): void {
                Route::get('/me', [TokenAuthController::class, 'me'])->name('me');
                Route::post('/logout', [TokenAuthController::class, 'logout'])->name('logout');
                Route::post('/logout-all', [TokenAuthController::class, 'logoutAll'])->name('logout-all');
            });
        });

    Route::middleware(['auth:sanctum'])
        ->get('/live-scoreboard', LiveScoreboardController::class)
        ->name('live-scoreboard.show');

    Route::middleware(['auth:sanctum'])
        ->prefix('/user-bets')
        ->name('user-bets.')
        ->group(function (): void {
            Route::get('/', [BetTrackerController::class, 'index'])->name('index');
            Route::post('/', [BetTrackerController::class, 'store'])->name('store');
            Route::put('/{bet}', [BetTrackerController::class, 'update'])->name('update');
            Route::delete('/{bet}', [BetTrackerController::class, 'destroy'])->name('destroy');
            Route::get('/export', [BetTrackerController::class, 'export'])->name('export');
        });

    Route::middleware(['auth:sanctum'])
        ->prefix('/cbb-brackets')
        ->name('cbb-brackets.')
        ->group(function (): void {
            Route::get('/leaderboard', [CbbBracketController::class, 'leaderboard'])->name('leaderboard');
            Route::get('/', [CbbBracketController::class, 'index'])->name('index');
            Route::post('/', [CbbBracketController::class, 'store'])->name('store');
            Route::get('/current', [CbbBracketController::class, 'showCurrent'])->name('current.show');
            Route::put('/current', [CbbBracketController::class, 'upsertCurrent'])->name('current.upsert');
            Route::get('/{publicId}', [CbbBracketController::class, 'show'])->name('show');
            Route::patch('/{publicId}', [CbbBracketController::class, 'update'])->name('update');
            Route::delete('/{publicId}', [CbbBracketController::class, 'destroy'])->name('destroy');
        });

    Route::middleware(['auth:sanctum'])
        ->prefix('/groups')
        ->name('groups.')
        ->group(function (): void {
            Route::get('/', [GroupController::class, 'index'])->name('index');
            Route::post('/', [GroupController::class, 'store'])->name('store');
            Route::patch('/{publicId}', [GroupController::class, 'update'])->name('update');
        });

    Route::middleware(['auth:sanctum'])
        ->prefix('/alert-preferences')
        ->name('alert-preferences.')
        ->group(function (): void {
            Route::get('/', [AlertPreferenceController::class, 'show'])->name('show');
            Route::post('/', [AlertPreferenceController::class, 'store'])->name('store');
            Route::put('/', [AlertPreferenceController::class, 'update'])->name('update');
        });

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
            Route::get('/games/{game}/page', SportGamePageController::class)->name('games.page.show');
            Route::get('/games/{game}/trends', SportGameTrendController::class)->name('games.trends.show');
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
            Route::get('/teams/{team}/stats/season-averages', [SportTeamStatAverageController::class, 'teamShow'])->name('teams.stats.season-averages.show');
            Route::get('/teams/{team}/trends', [SportTeamTrendController::class, 'show'])->name('teams.trends.show');

            Route::get('/players', [SportPlayerController::class, 'index'])->name('players.index');
            Route::get('/players/{player}', [SportPlayerController::class, 'show'])->name('players.show');
            Route::get('/players/{player}/player-props', [SportPlayerPropController::class, 'playerIndex'])->name('players.player-props.index');

            Route::get('/player-props', [SportPlayerPropController::class, 'index'])->name('player-props.index');
            Route::get('/player-props/board', [SportPlayerPropController::class, 'board'])->name('player-props.board');
            Route::get('/forecasts', [SportForecastController::class, 'index'])->name('forecasts.index');
            Route::get('/injuries', [SportInjuryController::class, 'index'])->name('injuries.index');
            Route::get('/signals', [SportSignalController::class, 'index'])->name('signals.index');
            Route::get('/daily-picks', [MlbDailyPickController::class, 'index'])->name('daily-picks.index');

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
            Route::get('/stats/team/season-averages', [SportTeamStatAverageController::class, 'index'])->name('stats.team.season-averages.index');
            Route::get('/stats/team', [SportStatController::class, 'teamIndex'])->name('stats.team.index');
        });
});
