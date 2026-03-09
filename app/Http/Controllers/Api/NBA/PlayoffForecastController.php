<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\PlayoffForecastResource;
use App\Models\NBA\PlayoffForecast;
use App\Support\SportsViewCache;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
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

        $season = (int) (($validated['season'] ?? null) ?: config('nba.season.default'));
        $allowedSorts = [
            'champion_probability',
            'playoff_make_probability',
            'direct_playoff_probability',
            'play_in_tournament_probability',
            'division_win_probability',
            'nba_finals_probability',
            'conference_finals_probability',
            'selection_score',
            'conference_rank',
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
                $requestedSeason = $season;
                $fallbackApplied = false;

                $forecasts = PlayoffForecast::query()
                    ->with('team')
                    ->where('season', $season)
                    ->orderBy($sortBy, $direction)
                    ->orderBy('playoff_make_probability', 'desc')
                    ->get();

                if ($forecasts->isEmpty()) {
                    $latestSeasonWithData = (int) (PlayoffForecast::query()->max('season') ?? 0);
                    if ($latestSeasonWithData > 0 && $latestSeasonWithData !== $season) {
                        $season = $latestSeasonWithData;
                        $fallbackApplied = true;
                        $forecasts = PlayoffForecast::query()
                            ->with('team')
                            ->where('season', $season)
                            ->orderBy($sortBy, $direction)
                            ->orderBy('playoff_make_probability', 'desc')
                            ->get();
                    }
                }

                $seasons = PlayoffForecast::query()
                    ->select('season')
                    ->distinct()
                    ->orderByDesc('season')
                    ->pluck('season')
                    ->values();

                $data = PlayoffForecastResource::collection($forecasts)->resolve($request);
                $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason('nba', $season);
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
                        'requested_season' => $requestedSeason,
                        'fallback_applied' => $fallbackApplied,
                        'available_seasons' => $seasons,
                        'playoff_teams_per_conference' => (int) config('nba.playoff_forecast.playoff_teams_per_conference', 8),
                        'play_in_teams_per_conference' => (int) config('nba.playoff_forecast.play_in_teams_per_conference', 10),
                    ],
                ];
            },
        );

        return response()->json($payload);
    }
}
