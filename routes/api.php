<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // NFL Routes
    Route::prefix('nfl')->name('nfl.')->group(base_path('routes/api/nfl.php'));

    // CFB Routes
    Route::prefix('cfb')->name('cfb.')->group(base_path('routes/api/cfb.php'));

    // CBB Routes
    Route::prefix('cbb')->name('cbb.')->group(base_path('routes/api/cbb.php'));

    // WCBB Routes
    Route::prefix('wcbb')->name('wcbb.')->group(base_path('routes/api/wcbb.php'));

    // NBA Routes
    Route::prefix('nba')->name('nba.')->group(base_path('routes/api/nba.php'));

    // WNBA Routes
    Route::prefix('wnba')->name('wnba.')->group(base_path('routes/api/wnba.php'));

    // MLB Routes
    Route::prefix('mlb')->name('mlb.')->group(base_path('routes/api/mlb.php'));

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
