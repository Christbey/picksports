<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Controller;
use App\Http\Resources\MLB\PlayoffForecastResource;
use App\Models\MLB\PlayoffForecast;
use App\Models\MLB\TeamMetric;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayoffForecastController extends Controller
{
    public function __construct(
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected FuturesEdgeService $futuresEdgeService,
        protected SportsViewCache $sportsViewCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'sort_by' => ['nullable', 'string'],
            'sort_direction' => ['nullable', 'string'],
        ]);

        $season = (int) (($validated['season'] ?? null) ?: config('mlb.season.default'));
        $allowedSorts = [
            'champion_probability',
            'playoff_make_probability',
            'world_series_probability',
            'league_championship_probability',
            'selection_score',
            'league_rank',
        ];

        $sortBy = (string) (($validated['sort_by'] ?? null) ?: 'champion_probability');
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'champion_probability';
        }

        $direction = strtolower((string) (($validated['sort_direction'] ?? null) ?: 'desc')) === 'asc'
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
                $forecasts = PlayoffForecast::query()
                    ->with('team')
                    ->where('season', $season)
                    ->orderBy($sortBy, $direction)
                    ->orderBy('playoff_make_probability', 'desc')
                    ->get();

                $hasCurrentSeasonMetrics = TeamMetric::query()
                    ->where('season', $season)
                    ->where('season_type', (string) config('mlb.season.types.regular', 2))
                    ->exists();

                $projectionSourceSeason = $hasCurrentSeasonMetrics
                    ? $season
                    : TeamMetric::query()
                        ->where('season', '<', $season)
                        ->where('season_type', (string) config('mlb.season.types.regular', 2))
                        ->max('season');

                $usedRegression = ! $hasCurrentSeasonMetrics && $projectionSourceSeason !== null;

                $seasons = PlayoffForecast::query()
                    ->select('season')
                    ->distinct()
                    ->orderByDesc('season')
                    ->pluck('season')
                    ->values();

                $data = PlayoffForecastResource::collection($forecasts)->resolve($request);
                $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason('mlb', $season);
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
                        'used_regression' => $usedRegression,
                        'projection_source_season' => $projectionSourceSeason ? (int) $projectionSourceSeason : null,
                    ],
                ];
            },
        );

        return response()->json($payload);
    }
}
