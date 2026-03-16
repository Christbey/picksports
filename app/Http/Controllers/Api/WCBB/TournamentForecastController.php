<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Actions\WCBB\GenerateTournamentForecast;
use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\TournamentForecastResource;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TournamentForecast;
use App\Services\Sports\FuturesEdgeService;
use App\Services\Sports\FuturesOddsLookupService;
use App\Support\SportsViewCache;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentForecastController extends Controller
{
    public function __construct(
        protected GenerateTournamentForecast $generateTournamentForecast,
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
                $this->refreshForecastIfNeeded($season);

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

    private function refreshForecastIfNeeded(int $season): void
    {
        $refreshConfig = (array) config('wcbb.tournament_forecast.refresh', []);
        if (($refreshConfig['enabled'] ?? true) !== true) {
            return;
        }

        $eligibleTeamCount = TeamMetric::query()
            ->where('season', $season)
            ->where('meets_minimum', true)
            ->count();

        if ($eligibleTeamCount === 0) {
            return;
        }

        $forecastSummary = TournamentForecast::query()
            ->where('season', $season)
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as newest_updated_at')
            ->first();

        $rowCount = (int) ($forecastSummary?->row_count ?? 0);
        $coverageRatio = $eligibleTeamCount > 0 ? $rowCount / $eligibleTeamCount : 1.0;
        $minimumCoverageRatio = max(0.0, min(1.0, (float) ($refreshConfig['minimum_coverage_ratio'] ?? 0.95)));
        $staleAfterHours = max(1, (int) ($refreshConfig['stale_after_hours'] ?? 6));
        $newestUpdatedAt = $forecastSummary?->newest_updated_at;

        $isStale = ! $newestUpdatedAt instanceof CarbonInterface
            || $newestUpdatedAt->lt(now()->subHours($staleAfterHours));

        if ($rowCount === 0 || $coverageRatio < $minimumCoverageRatio || $isStale) {
            $this->generateTournamentForecast->execute($season);
        }
    }
}
