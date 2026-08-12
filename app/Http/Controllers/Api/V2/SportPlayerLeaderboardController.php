<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportPlayerLeaderboardIndexRequest;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPlayerLeaderboardQuery;
use App\Services\Api\V2\SportStatQuery;
use App\Support\SportsViewCache;
use Illuminate\Http\JsonResponse;

class SportPlayerLeaderboardController extends Controller
{
    public function index(
        string $sport,
        SportPlayerLeaderboardIndexRequest $request,
        SportContextResolver $sports,
        SportPlayerLeaderboardQuery $leaderboards,
        SportsViewCache $cache,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $queryFilters = collect($filters)->except('focus_player_id')->all();
        $data = $cache->remember(
            SportsViewCache::SEGMENT_PLAYER_LEADERBOARDS,
            $cache->contextHash(['sport' => $context->slug, 'filters' => $queryFilters]),
            (int) config('performance.player_leaderboard_cache_seconds', 300),
            fn () => $leaderboards->get($context, $queryFilters, $request->user())->all(),
        );
        $focusPlayerId = isset($filters['focus_player_id']) ? (int) $filters['focus_player_id'] : null;
        $focusRanks = $focusPlayerId === null ? [] : $this->focusRanks($data, $focusPlayerId);

        if ($focusPlayerId !== null) {
            $data = array_values(array_filter(
                $data,
                fn (array $entry): bool => (int) ($entry['player_id'] ?? 0) === $focusPlayerId,
            ));
        }

        return response()->json([
            'data' => $data,
            'meta' => $this->meta($context->slug, 'sports.leaderboards.players.index', $filters) + [
                'focus_ranks' => $focusRanks,
            ],
        ]);
    }

    public function availableSeasons(
        string $sport,
        SportPlayerLeaderboardIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
        SportsViewCache $cache,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $data = $cache->remember(
            SportsViewCache::SEGMENT_PLAYER_STAT_SEASONS,
            $context->slug,
            (int) config('performance.player_stat_seasons_cache_seconds', 900),
            fn () => $stats->availableSeasons($context, 'player', $request->user()),
        );

        return response()->json([
            'data' => $data,
            'meta' => $this->meta($context->slug, 'sports.leaderboards.players.available-seasons'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function meta(string $sport, string $contract, array $filters = []): array
    {
        return [
            'version' => 'v2',
            'sport' => $sport,
            'contract' => $contract,
            'filters' => $filters,
            'tier' => [],
            'freshness' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{rank: int, total: int}>
     */
    private function focusRanks(array $rows, int $playerId): array
    {
        $target = collect($rows)->first(
            fn (array $entry): bool => (int) ($entry['player_id'] ?? 0) === $playerId,
        );

        if (! is_array($target)) {
            return [];
        }

        $ranks = [];
        foreach ($target as $metric => $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $eligible = collect($rows)
                ->filter(fn (array $entry): bool => is_numeric($entry[$metric] ?? null) && (float) $entry[$metric] > 0)
                ->sortByDesc(fn (array $entry): float => (float) $entry[$metric])
                ->values();
            $index = $eligible->search(
                fn (array $entry): bool => (int) ($entry['player_id'] ?? 0) === $playerId,
            );

            if ($index !== false) {
                $ranks[$metric] = [
                    'rank' => $index + 1,
                    'total' => $eligible->count(),
                ];
            }
        }

        return $ranks;
    }
}
