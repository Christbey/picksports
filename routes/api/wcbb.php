<?php

use App\Http\Controllers\Api\WCBB\PredictionController;
use App\Http\Controllers\Api\WCBB\TeamMetricController;
use Illuminate\Support\Facades\Route;

// Team Metrics (requires authentication for tier limits)
Route::middleware(['web', 'auth'])->group(function () {
    Route::apiResource('team-metrics', TeamMetricController::class)->only(['index', 'show']);
    Route::get('teams/{team}/metrics', [TeamMetricController::class, 'byTeam']);
});

// Predictions (requires authentication for tier limits)
Route::middleware(['web', 'auth'])->group(function () {
    Route::apiResource('predictions', PredictionController::class)->only(['index', 'show']);
});
