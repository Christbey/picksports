<?php

namespace App\Http\Controllers;

use App\Services\Api\V2\LiveScoreboardPayloadService;
use Illuminate\Http\JsonResponse;

class LiveScoreboardController extends Controller
{
    public function __invoke(LiveScoreboardPayloadService $scoreboard): JsonResponse
    {
        return response()->json($scoreboard->payload(auth()->user()));
    }
}
