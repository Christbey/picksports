<?php

use App\Http\Controllers\BetTrackerController;
use Illuminate\Support\Facades\Route;

// User Bets
Route::get('/', [BetTrackerController::class, 'index'])->name('index');
Route::post('/', [BetTrackerController::class, 'store'])->name('store');
Route::put('/{bet}', [BetTrackerController::class, 'update'])->name('update');
Route::delete('/{bet}', [BetTrackerController::class, 'destroy'])->name('destroy');
Route::get('/export', [BetTrackerController::class, 'export'])->name('export');
