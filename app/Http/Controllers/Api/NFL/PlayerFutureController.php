<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Controller;
use App\Services\NFL\PlayerFuturesProjectionService;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerFutureController extends Controller
{
    public function __construct(
        protected PlayerFuturesProjectionService $projectionService,
        protected SportsViewCache $sportsViewCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $supportedMarkets = array_keys($this->projectionService->supportedMarkets());

        $validated = $request->validate([
            'season' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'market' => ['nullable', 'string', 'in:'.implode(',', $supportedMarkets)],
            'player_id' => ['nullable', 'integer'],
            'as_of_week' => ['nullable', 'integer', 'min:1', 'max:18'],
            'only_with_odds' => ['nullable', 'boolean'],
            'sort_by' => ['nullable', 'string', 'in:edge,projected_total,current_total,over_probability,under_probability'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $season = (int) (($validated['season'] ?? null) ?: config('nfl.season.default'));
        $market = isset($validated['market']) ? (string) $validated['market'] : null;
        $playerId = isset($validated['player_id']) ? (int) $validated['player_id'] : null;
        $asOfWeek = isset($validated['as_of_week']) ? (int) $validated['as_of_week'] : null;
        $onlyWithOdds = array_key_exists('only_with_odds', $validated)
            ? (bool) $validated['only_with_odds']
            : true;
        $sortBy = (string) (($validated['sort_by'] ?? null) ?: 'edge');
        $direction = strtolower((string) (($validated['sort_direction'] ?? null) ?: 'desc')) === 'asc'
            ? 'asc'
            : 'desc';
        $limit = (int) (($validated['limit'] ?? null) ?: 100);

        if ($asOfWeek !== null && $onlyWithOdds) {
            return response()->json([
                'message' => 'Historical player futures queries with as_of_week currently require only_with_odds=false because player futures odds snapshots are not yet stored.',
                'errors' => [
                    'only_with_odds' => [
                        'Historical player futures queries with as_of_week currently require only_with_odds=false because player futures odds snapshots are not yet stored.',
                    ],
                ],
            ], 422);
        }

        $cacheKey = $this->sportsViewCache->contextHash([
            'controller' => static::class,
            'season' => $season,
            'market' => $market,
            'player_id' => $playerId,
            'as_of_week' => $asOfWeek,
            'only_with_odds' => $onlyWithOdds,
            'sort_by' => $sortBy,
            'sort_direction' => $direction,
            'limit' => $limit,
        ]);

        $payload = $this->sportsViewCache->remember(
            segment: SportsViewCache::SEGMENT_FUTURES_FORECASTS,
            key: $cacheKey,
            ttlSeconds: (int) config('sports_view_cache.ttl.futures_forecasts_seconds', 120),
            resolver: fn (): array => [
                'data' => $this->projectionService->projections(
                    season: $season,
                    market: $market,
                    playerId: $playerId,
                    onlyWithOdds: $onlyWithOdds,
                    sortBy: $sortBy,
                    direction: $direction,
                    limit: $limit,
                    asOfWeek: $asOfWeek,
                ),
                'meta' => [
                    'season' => $season,
                    'market' => $market,
                    'player_id' => $playerId,
                    'as_of_week' => $asOfWeek,
                    'only_with_odds' => $onlyWithOdds,
                    'sort_by' => $sortBy,
                    'sort_direction' => $direction,
                    'limit' => $limit,
                    'supported_markets' => array_map(
                        static fn (array $definition): array => [
                            'label' => (string) ($definition['label'] ?? ''),
                            'stat_field' => (string) ($definition['stat_field'] ?? ''),
                        ],
                        $this->projectionService->supportedMarkets()
                    ),
                ],
            ],
        );

        return response()->json($payload);
    }
}
