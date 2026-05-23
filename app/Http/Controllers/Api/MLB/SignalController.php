<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Controller;
use App\Services\MLB\MlbBettingSignalService;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SignalController extends Controller
{
    public function __construct(
        protected MlbBettingSignalService $signalService,
        protected SportsViewCache $sportsViewCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $season = (int) (($validated['season'] ?? null) ?: config('mlb.season.default'));
        $asOfDate = isset($validated['as_of_date']) ? Carbon::parse((string) $validated['as_of_date']) : now();
        $cacheKey = $this->sportsViewCache->contextHash([
            'controller' => static::class,
            'season' => $season,
            'as_of_date' => $asOfDate->toDateString(),
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
            key: $cacheKey,
            ttlSeconds: 120,
            resolver: fn (): array => [
                'data' => $this->signalService->signals($season, $asOfDate),
            ],
        );

        return response()->json($payload);
    }
}
