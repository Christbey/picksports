<?php

use App\Http\Controllers\Api\GroupController;
use Illuminate\Support\Facades\Route;

Route::get('/', [GroupController::class, 'index'])->name('index');
Route::post('/', [GroupController::class, 'store'])->name('store');
Route::patch('/{publicId}', [GroupController::class, 'update'])->name('update');
