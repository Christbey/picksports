<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportStatIndexRequest;
use App\Http\Resources\Api\V2\SportStatResource;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportStatQuery;
use Illuminate\Http\JsonResponse;

class SportStatController extends Controller
{
    public function playerIndex(
        string $sport,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        return $this->index($sport, 'player', $request, $sports, $stats);
    }

    public function teamIndex(
        string $sport,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        return $this->index($sport, 'team', $request, $sports, $stats);
    }

    public function playerAvailableSeasons(
        string $sport,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        return $this->availableSeasons($sport, 'player', $request, $sports, $stats);
    }

    public function teamAvailableSeasons(
        string $sport,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        return $this->availableSeasons($sport, 'team', $request, $sports, $stats);
    }

    public function playerAvailableDates(
        string $sport,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        return $this->availableDates($sport, 'player', $request, $sports, $stats);
    }

    public function teamAvailableDates(
        string $sport,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        return $this->availableDates($sport, 'team', $request, $sports, $stats);
    }

    private function index(
        string $sport,
        string $type,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $stats->paginate($context, $type, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($stat) => new SportStatResource($stat, $context, $type))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => array_merge($this->meta($context->slug, $type, "sports.stats.{$type}.index", $filters), [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'raw_stats' => [
                    'strategy' => 'stats_bag',
                    'field' => 'stats',
                ],
                'warnings' => $this->warnings($context, $type, $filters, $stats),
            ]),
        ]);
    }

    private function availableSeasons(
        string $sport,
        string $type,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        $context = $sports->resolve($sport);

        return response()->json([
            'data' => $stats->availableSeasons($context, $type, $request->user()),
            'meta' => $this->meta($context->slug, $type, "sports.stats.{$type}.available-seasons"),
        ]);
    }

    private function availableDates(
        string $sport,
        string $type,
        SportStatIndexRequest $request,
        SportContextResolver $sports,
        SportStatQuery $stats,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();

        return response()->json([
            'data' => $stats->availableDates($context, $type, $filters, $request->user()),
            'meta' => $this->meta($context->slug, $type, "sports.stats.{$type}.available-dates", $filters),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function meta(string $sport, string $type, string $contract, array $filters = []): array
    {
        return [
            'version' => 'v2',
            'sport' => $sport,
            'stat_type' => $type,
            'contract' => $contract,
            'filters' => $filters,
            'tier' => [],
            'freshness' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function warnings(SportContext $context, string $type, array $filters, SportStatQuery $stats): array
    {
        $gameId = (int) ($filters['game_id'] ?? 0);

        if ($gameId < 1) {
            return [];
        }

        $game = $stats->gameForId($context, $gameId);

        if (! $game) {
            return [[
                'code' => 'game_not_found',
                'severity' => 'warning',
                'message' => 'No game exists for the requested stat game_id.',
            ]];
        }

        $status = (string) ($game->status ?? '');

        if (in_array($status, ['STATUS_FINAL', 'STATUS_FULL_TIME'], true)) {
            return [];
        }

        return [[
            'code' => 'postgame_stats_not_available',
            'severity' => 'info',
            'message' => ucfirst($type).' box-score stats are not expected until the game is final.',
            'game_id' => $gameId,
            'game_status' => $status,
        ]];
    }
}
