<?php

use App\Http\Controllers\BetTrackerController;
use Illuminate\Support\Facades\Route;

// User Bets
Route::get('/', [BetTrackerController::class, 'index']);
Route::post('/', [BetTrackerController::class, 'store']);
Route::put('/{bet}', [BetTrackerController::class, 'update']);
Route::delete('/{bet}', [BetTrackerController::class, 'destroy']);
Route::get('/export', [BetTrackerController::class, 'export']);
