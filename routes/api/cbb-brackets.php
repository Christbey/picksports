<?php

use App\Http\Controllers\Api\CBB\BracketController;
use Illuminate\Support\Facades\Route;

Route::get('/leaderboard', [BracketController::class, 'leaderboard']);
Route::get('/', [BracketController::class, 'index']);
Route::post('/', [BracketController::class, 'store']);
Route::get('/current', [BracketController::class, 'showCurrent']);
Route::put('/current', [BracketController::class, 'upsertCurrent']);
Route::get('/{publicId}', [BracketController::class, 'show']);
Route::patch('/{publicId}', [BracketController::class, 'update']);
Route::delete('/{publicId}', [BracketController::class, 'destroy']);
