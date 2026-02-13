<?php

use App\Http\Controllers\AlertPreferenceController;
use Illuminate\Support\Facades\Route;

// Alert Preferences
Route::get('/', [AlertPreferenceController::class, 'show']);
Route::post('/', [AlertPreferenceController::class, 'store']);
Route::put('/', [AlertPreferenceController::class, 'update']);
