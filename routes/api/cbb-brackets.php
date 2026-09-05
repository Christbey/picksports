<?php

use App\Http\Controllers\Api\CBB\BracketController;
use Illuminate\Support\Facades\Route;

Route::get('/leaderboard', [BracketController::class, 'leaderboard'])->name('leaderboard');
Route::get('/', [BracketController::class, 'index'])->name('index');
Route::post('/', [BracketController::class, 'store'])->name('store');
Route::get('/current', [BracketController::class, 'showCurrent'])->name('current.show');
Route::put('/current', [BracketController::class, 'upsertCurrent'])->name('current.upsert');
Route::get('/{publicId}', [BracketController::class, 'show'])->name('show');
Route::patch('/{publicId}', [BracketController::class, 'update'])->name('update');
Route::delete('/{publicId}', [BracketController::class, 'destroy'])->name('destroy');
