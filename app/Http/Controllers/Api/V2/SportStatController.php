<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportStatIndexRequest;
use App\Http\Resources\Api\V2\SportStatResource;
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
            'meta' => $this->meta($context->slug, $type, "sports.stats.{$type}.index", $filters) + [
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
            ],
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
}
