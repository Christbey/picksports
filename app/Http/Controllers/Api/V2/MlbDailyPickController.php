<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\MlbPickCandidateResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\MLB\Picks\MlbDailyTopPickSelector;
use App\Services\MLB\Picks\MlbPickCandidateRepository;
use App\Services\MLB\Picks\MlbPickExplanationService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MlbDailyPickController extends Controller
{
    public function index(
        string $sport,
        Request $request,
        SportContextResolver $sports,
        SportsDateWindowService $dates,
        MlbPickCandidateRepository $repository,
        MlbDailyTopPickSelector $selector,
        MlbPickExplanationService $explanations,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        abort_unless($context->slug === 'mlb', 404, 'Daily pick candidates are currently supported for MLB only.');

        $date = $dates->parseLocalDate($request->query('date'));
        $season = $request->query('season') ? (int) $request->query('season') : null;
        $candidates = $repository->forDate($date, $season);
        $topPicks = $selector->select($candidates->toBase(), $request->query('limit') ? (int) $request->query('limit') : null);

        $resource = fn ($row): array => (new MlbPickCandidateResource($row, $explanations))->toArray($request);

        return response()->json([
            'data' => [
                'date' => $date->toDateString(),
                'mode' => 'tracking_only',
                'target_count' => (int) config('mlb.picks.daily.target_count', 3),
                'public_promoted_count' => $candidates->where('is_public', true)->count(),
                'candidate_count' => $candidates->count(),
                'top_picks' => $topPicks->map($resource)->values()->all(),
                'candidates' => $candidates->map($resource)->values()->all(),
                'blocked_reasons' => (bool) config('mlb.picks.public_promotion_enabled', false)
                    ? []
                    : ['mlb_public_promotion_unvalidated'],
            ],
            'meta' => [
                'version' => 'v2',
                'sport' => 'mlb',
                'contract' => 'sports.daily-picks.index',
                'filters' => [
                    'date' => $date->toDateString(),
                    'season' => $season,
                ],
            ],
        ]);
    }
}
