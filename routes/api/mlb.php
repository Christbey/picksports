<?php

use App\Http\Controllers\Api\MLB\PredictionController;
use App\Http\Controllers\Api\MLB\TeamController;
use App\Http\Controllers\Api\MLB\TeamMetricController;
use Illuminate\Support\Facades\Route;

// Teams
Route::apiResource('teams', TeamController::class);
Route::middleware('web')->get('teams/{team}/trends', [TeamController::class, 'trends']);

// Team Metrics
Route::apiResource('team-metrics', TeamMetricController::class)->only(['index', 'show']);

// Predictions (requires authentication for tier limits)
Route::middleware(['web', 'auth'])->group(function () {
    Route::apiResource('predictions', PredictionController::class)->only(['index', 'show']);
    Route::get('games/{game}/prediction', [PredictionController::class, 'byGame']);
});
