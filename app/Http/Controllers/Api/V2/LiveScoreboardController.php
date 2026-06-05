<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Api\V2\LiveScoreboardPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveScoreboardController extends Controller
{
    public function __invoke(Request $request, LiveScoreboardPayloadService $scoreboard): JsonResponse
    {
        return response()->json([
            'data' => $scoreboard->payload($request->user()),
            'meta' => [
                'version' => 'v2',
                'contract' => 'live-scoreboard.show',
                'tier' => [
                    'mode' => 'sanitized_default',
                    'allowed_field_groups' => ['scoreboard', 'live_status', 'prediction_summary'],
                    'withheld_field_groups' => ['raw_model_metadata', 'ai_analysis'],
                ],
                'freshness' => [],
                'warnings' => [],
            ],
        ]);
    }
}
