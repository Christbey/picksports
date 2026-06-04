<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportPlayerLeaderboardIndexRequest;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPlayerLeaderboardQuery;
use App\Services\Api\V2\SportStatQuery;
use Illuminate\Http\JsonResponse;

class SportPlayerLeaderboardController extends Controller
{
    public function index(
        string $sport,
        SportPlayerLeaderboardIndexRequest $request,
        SportContextResolver $sports,
        SportPlayerLeaderboardQuery $leaderboards,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();

        return response()->json([
            'data' => $leaderboards->get($context, $filters, $request->user()),
            'meta' => $this->meta($context->slug, 'sports.leaderboards.players.index', $filters),
        ]);
    }

    public function availableSeasons(
        string $sport,
        SportPlayerLeaderboardIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        $context = $sports->resolve($sport);

        return response()->json([
            'data' => $stats->availableSeasons($context, 'player', $request->user()),
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
}
