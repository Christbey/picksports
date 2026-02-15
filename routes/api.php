<?php

use Illuminate\Support\Facades\Route;

// Load generic sport route definer
$registerSportRoutes = require base_path('routes/api/sports.php');

Route::prefix('v1')->group(function () use ($registerSportRoutes) {
    // Sport Routes (using generic route definer)
    Route::prefix('nfl')->name('nfl.')->group(fn () => $registerSportRoutes('nfl', 'NFL'));
    Route::prefix('cfb')->name('cfb.')->group(fn () => $registerSportRoutes('cfb', 'CFB'));
    Route::prefix('cbb')->name('cbb.')->group(fn () => $registerSportRoutes('cbb', 'CBB'));
    Route::prefix('wcbb')->name('wcbb.')->group(fn () => $registerSportRoutes('wcbb', 'WCBB'));
    Route::prefix('nba')->name('nba.')->group(fn () => $registerSportRoutes('nba', 'NBA'));
    Route::prefix('wnba')->name('wnba.')->group(fn () => $registerSportRoutes('wnba', 'WNBA'));
    // MLB routes temporarily disabled - controllers need to be created
    // Route::prefix('mlb')->name('mlb.')->group(fn () => $registerSportRoutes('mlb', 'MLB'));

    // User Bets Routes (protected by auth)
    Route::middleware('auth')->prefix('user-bets')->name('user-bets.')->group(base_path('routes/api/user-bets.php'));

    // Alert Preferences Routes (protected by auth)
    Route::middleware('auth')->prefix('alert-preferences')->name('alert-preferences.')->group(base_path('routes/api/alert-preferences.php'));

    // Onboarding Routes (protected by auth)
    Route::middleware('auth')->prefix('onboarding')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\OnboardingController::class, 'index']);
        Route::get('/steps', [\App\Http\Controllers\Api\OnboardingController::class, 'steps']);
        Route::get('/checklist', [\App\Http\Controllers\Api\OnboardingController::class, 'checklist']);
        Route::post('/steps/complete', [\App\Http\Controllers\Api\OnboardingController::class, 'completeStep']);
        Route::post('/personalization', [\App\Http\Controllers\Api\OnboardingController::class, 'savePersonalization']);
        Route::post('/skip', [\App\Http\Controllers\Api\OnboardingController::class, 'skip']);
    });
});
