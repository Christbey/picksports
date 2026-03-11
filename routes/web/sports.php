<?php

use App\Http\Controllers\BettingRecommendationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Debug\PredictionAccessController as DebugPredictionAccessController;
use App\Http\Controllers\LiveScoreboardController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

$sportDomains = (array) config('sports.domains', []);

foreach ($sportDomains as $sport => $definition) {
    $web = (array) ($definition['web'] ?? []);
    if (($web['player_props'] ?? false) !== true) {
        continue;
    }

    Route::get("/{$sport}/player-props", [BettingRecommendationsController::class, $sport])
        ->middleware(['auth', 'onboarded', 'verified', "permission:view-{$sport}-predictions"])
        ->name("{$sport}.player-props");
}

Route::get('betting-recommendations', fn () => redirect()->route('nba.player-props'));

Route::get('dashboard', DashboardController::class)->middleware(['auth', 'onboarded', 'verified'])->name('dashboard');
Route::get('live-scoreboard', LiveScoreboardController::class)
    ->middleware(['auth', 'onboarded', 'verified'])
    ->name('live-scoreboard');

Route::get('my-bets', function () {
    return Inertia::render('MyBets');
})->middleware(['auth', 'onboarded', 'verified'])->name('my-bets');

Route::get('debug/prediction-access', DebugPredictionAccessController::class)
    ->middleware(['auth', 'onboarded', 'verified'])
    ->name('debug.prediction-access');

foreach ($sportDomains as $sport => $definition) {
    $page = (string) (($definition['web']['predictions_page'] ?? null) ?: '');
    if ($page === '') {
        continue;
    }

    Route::get("/{$sport}/predictions", fn () => Inertia::render($page))
        ->middleware(['auth', 'onboarded', 'verified', "permission:view-{$sport}-predictions"])
        ->name("{$sport}-predictions");
}

foreach ($sportDomains as $sport => $definition) {
    $pages = (array) ($definition['web']['pages'] ?? []);
    foreach ($pages as $suffix => $page) {
        $path = "/{$sport}/{$suffix}";
        Route::get($path, fn () => Inertia::render($page))
            ->middleware(['auth', 'onboarded', 'verified', "permission:view-{$sport}-predictions"])
            ->name("{$sport}-{$suffix}");
    }
}

foreach ($sportDomains as $sport => $definition) {
    $namespace = (string) ($definition['namespace'] ?? '');
    if ($namespace === '') {
        continue;
    }

    $details = (array) ($definition['web']['details'] ?? []);
    $sportDetailMiddleware = ['auth', 'onboarded', 'verified', "permission:view-{$sport}-predictions"];
    $teamController = "App\\Http\\Controllers\\{$namespace}\\TeamController";
    $gameController = "App\\Http\\Controllers\\{$namespace}\\GameController";
    $playerController = "App\\Http\\Controllers\\{$namespace}\\PlayerController";

    if (($details['team'] ?? false) === true && class_exists($teamController)) {
        Route::get("/{$sport}/teams/{team}", $teamController)->middleware($sportDetailMiddleware);
    }

    if (($details['game'] ?? false) === true && class_exists($gameController)) {
        Route::get("/{$sport}/games/{game}", $gameController)->middleware($sportDetailMiddleware);
    }

    if (($details['player'] ?? false) === true && class_exists($playerController)) {
        Route::get("/{$sport}/players/{player}", $playerController)
            ->middleware($sportDetailMiddleware)
            ->name("{$sport}.player.show");
    }
}
