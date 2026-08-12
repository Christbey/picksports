<?php

use App\Http\Controllers\Auth\PasskeyController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->prefix('passkeys')->name('passkeys.')->group(function () {
    Route::post('/authentication/options', [PasskeyController::class, 'authenticationOptions'])
        ->name('authentication.createOptions');
    Route::post('/authentication/verify', [PasskeyController::class, 'authenticate'])
        ->name('authentication.verify');
});

Route::middleware(['auth'])->prefix('passkeys')->name('passkeys.')->group(function () {
    Route::get('/', [PasskeyController::class, 'index'])->name('index');
    Route::post('/registration/options', [PasskeyController::class, 'registrationOptions'])
        ->name('registration.createOptions');
    Route::post('/registration/verify', [PasskeyController::class, 'register'])
        ->name('registration.verify');
    Route::delete('/{passkey}', [PasskeyController::class, 'destroy'])->name('destroy');
});
