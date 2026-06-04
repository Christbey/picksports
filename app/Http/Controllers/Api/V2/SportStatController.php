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
            'meta' => [
                'version' => 'v2',
                'sport' => $context->slug,
                'stat_type' => $type,
                'contract' => "sports.stats.{$type}.index",
                'filters' => $filters,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'tier' => [],
                'freshness' => [],
                'warnings' => [],
                'raw_stats' => [
                    'strategy' => 'stats_bag',
                    'field' => 'stats',
                ],
            ],
        ]);
    }
}
