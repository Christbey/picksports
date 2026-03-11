<?php

use App\Http\Controllers\Auth\OauthController;
use App\Http\Controllers\Auth\OauthOnboardingController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('auth/{provider}/redirect', [OauthController::class, 'redirect'])
        ->name('oauth.redirect');
    Route::get('auth/{provider}/callback', [OauthController::class, 'callback'])
        ->name('oauth.callback');
});

Route::middleware('auth')->group(function () {
    Route::get('auth/onboarding', [OauthOnboardingController::class, 'show'])
        ->name('oauth.onboarding.show');
    Route::post('auth/onboarding', [OauthOnboardingController::class, 'store'])
        ->name('oauth.onboarding.store');
});
