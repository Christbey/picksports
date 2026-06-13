<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\PlayoffForecastResource;
use App\Services\NFL\TeamPlayoffForecastService;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayoffForecastController extends Controller
{
    public function __construct(
        protected TeamPlayoffForecastService $forecastService,
        protected FuturesOddsLookupService $futuresOddsLookup,
        protected FuturesEdgeService $futuresEdgeService,
        protected SportsViewCache $sportsViewCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'as_of_date' => ['nullable', 'date'],
            'require_historical_metrics' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', 'in:projected_wins,make_playoffs_probability,division_winner_probability,conference_champion_probability,super_bowl_champion_probability,projected_seed'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $season = (int) (($validated['season'] ?? null) ?: config('nfl.season.default'));
        $asOfDate = isset($validated['as_of_date']) ? (string) $validated['as_of_date'] : null;
        $requireHistoricalMetrics = (bool) ($validated['require_historical_metrics'] ?? false);
        $sortBy = (string) (($validated['sort_by'] ?? null) ?: 'super_bowl_champion_probability');
        $direction = strtolower((string) (($validated['sort_direction'] ?? null) ?: 'desc')) === 'asc'
            ? 'asc'
            : 'desc';

        $cacheKey = $this->sportsViewCache->contextHash([
            'controller' => static::class,
            'season' => $season,
            'as_of_date' => $asOfDate,
            'require_historical_metrics' => $requireHistoricalMetrics,
            'sort_by' => $sortBy,
            'sort_direction' => $direction,
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: SportsViewCache::SEGMENT_FUTURES_FORECASTS,
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.futures_forecasts_seconds', 120),
            resolver: function () use ($season, $asOfDate, $requireHistoricalMetrics, $sortBy, $direction): array {
                $report = $this->forecastService->forecast(
                    season: $season,
                    asOfDate: $asOfDate,
                    requireHistoricalMetrics: $requireHistoricalMetrics,
                );

                $data = array_values($report['teams'] ?? []);
                usort($data, function (array $left, array $right) use ($sortBy, $direction): int {
                    $comparison = (($left[$sortBy] ?? 0) <=> ($right[$sortBy] ?? 0));

                    return $direction === 'asc' ? $comparison : -$comparison;
                });

                $marketOddsByTeam = $this->futuresOddsLookup->byTeamForSeason(
                    'nfl',
                    $season,
                    $this->futuresOddsLookup->championshipMarketKeys()
                );
                $data = array_map(function (array $row) use ($marketOddsByTeam): array {
                    $teamId = (int) ($row['team_id'] ?? 0);
                    $row['market_odds'] = $marketOddsByTeam[$teamId] ?? null;

                    return $row;
                }, $data);
                $data = $this->futuresEdgeService->annotate($data, 'super_bowl_champion_probability');
                $data = PlayoffForecastResource::collection(collect($data))->resolve();

                return [
                    'data' => $data,
                    'meta' => [
                        'season' => $season,
                        'as_of_date' => $asOfDate,
                        'require_historical_metrics' => $requireHistoricalMetrics,
                        'sort_by' => $sortBy,
                        'sort_direction' => $direction,
                        'simulations' => data_get($report, 'summary.simulations'),
                    ],
                ];
            },
        );

        return response()->json($payload);
    }
}
