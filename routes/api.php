<?php

use Illuminate\Support\Facades\Route;

// Load generic sport route definer
$registerSportRoutes = require base_path('routes/api/sports.php');

Route::prefix('v1')->group(function () use ($registerSportRoutes) {
    // Sport Routes (using generic route definer)
    foreach ([
        'nfl' => 'NFL',
        'cfb' => 'CFB',
        'cbb' => 'CBB',
        'wcbb' => 'WCBB',
        'nba' => 'NBA',
        'wnba' => 'WNBA',
        'mlb' => 'MLB',
    ] as $sport => $namespace) {
        Route::prefix($sport)->name("{$sport}.")->group(fn () => $registerSportRoutes($sport, $namespace));
    }

    foreach ([
        'user-bets' => 'routes/api/user-bets.php',
        'alert-preferences' => 'routes/api/alert-preferences.php',
    ] as $prefix => $file) {
        Route::middleware('auth:sanctum')
            ->prefix($prefix)
            ->name("{$prefix}.")
            ->group(base_path($file));
    }

    // Onboarding Routes (protected by auth)
    Route::middleware('auth:sanctum')->prefix('onboarding')->group(function () {
        foreach ([
            ['get', '/', 'index'],
            ['get', '/steps', 'steps'],
            ['get', '/checklist', 'checklist'],
            ['post', '/steps/complete', 'completeStep'],
            ['post', '/personalization', 'savePersonalization'],
            ['post', '/skip', 'skip'],
        ] as [$method, $uri, $action]) {
            Route::$method($uri, [\App\Http\Controllers\Api\OnboardingController::class, $action]);
        }
    });
});
