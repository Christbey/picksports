<?php

namespace App\Http\Controllers\Api\Sports;

use App\Http\Controllers\Controller;
use App\Services\Predictions\PredictionAccessInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PredictionAccessDebugController extends Controller
{
    public function __construct(private readonly PredictionAccessInspector $inspector) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        return response()->json([
            'data' => $this->inspector->inspect($user, (string) $request->route('sport', '')),
        ]);
    }
}
