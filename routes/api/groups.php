<?php

use App\Http\Controllers\Api\GroupController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GroupController::class, 'index']);
Route::post('/', [GroupController::class, 'store']);
Route::patch('/{publicId}', [GroupController::class, 'update']);
