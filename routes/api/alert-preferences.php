<?php

use App\Http\Controllers\AlertPreferenceController;
use Illuminate\Support\Facades\Route;

// Alert Preferences
Route::get('/', [AlertPreferenceController::class, 'show'])->name('show');
Route::post('/', [AlertPreferenceController::class, 'store'])->name('store');
Route::put('/', [AlertPreferenceController::class, 'update'])->name('update');
