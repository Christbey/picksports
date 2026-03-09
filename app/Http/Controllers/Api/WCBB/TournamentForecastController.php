<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\TournamentForecastResource;
use App\Models\WCBB\TournamentForecast;
use App\Support\SportsViewCache;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentForecastController extends Controller
{
    public function __construct(
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected FuturesEdgeService $futuresEdgeService,
        protected SportsViewCache $sportsViewCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $season = (int) ($request->integer('season') ?: config('wcbb.season.default'));
        $allowedSorts = [
            'champion_probability',
            'tournament_make_probability',
            'auto_bid_probability',
            'at_large_probability',
            'bid_thief_probability',
            'selection_score',
        ];

        $sortBy = (string) ($request->query('sort_by', 'champion_probability'));
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'champion_probability';
        }

        $direction = strtolower((string) $request->query('sort_direction', 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $cacheKey = $this->sportsViewCache->contextHash([
            'controller' => static::class,
            'season' => $season,
            'sort_by' => $sortBy,
            'sort_direction' => $direction,
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: SportsViewCache::SEGMENT_FUTURES_FORECASTS,
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.futures_forecasts_seconds', 120),
            resolver: function () use ($season, $sortBy, $direction, $request): array {
                $forecasts = TournamentForecast::query()
                    ->with('team')
                    ->where('season', $season)
                    ->orderBy($sortBy, $direction)
                    ->orderBy('tournament_make_probability', 'desc')
                    ->get();

                $seasons = TournamentForecast::query()
                    ->select('season')
                    ->distinct()
                    ->orderByDesc('season')
                    ->pluck('season')
                    ->values();

                $data = TournamentForecastResource::collection($forecasts)->resolve($request);
                $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason('wcbb', $season);
                $data = array_map(function (array $row) use ($marketOddsByTeam): array {
                    $teamId = (int) ($row['team_id'] ?? 0);
                    $row['market_odds'] = $marketOddsByTeam[$teamId] ?? null;

                    return $row;
                }, $data);
                $data = $this->futuresEdgeService->annotate($data, 'champion_probability');

                return [
                    'data' => $data,
                    'meta' => [
                        'season' => $season,
                        'available_seasons' => $seasons,
                    ],
                ];
            },
        );

        return response()->json($payload);
    }
}
