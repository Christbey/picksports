<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ML\TrainingMonitoringService;
use Illuminate\Http\JsonResponse;

class TrainingOperationsController extends Controller
{
    public function __invoke(string $sport, TrainingMonitoringService $monitoring): JsonResponse
    {
        return response()->json($monitoring->dashboard($sport))->header('Cache-Control', 'private, no-store');
    }
}
