<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportFuturesOddIndexRequest;
use App\Http\Resources\Api\V2\SportFuturesOddResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportFuturesOddQuery;
use Illuminate\Http\JsonResponse;

class SportFuturesOddController extends Controller
{
    public function index(
        string $sport,
        SportFuturesOddIndexRequest $request,
        SportContextResolver $sports,
        SportFuturesOddQuery $futures,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $futures->paginate($context->slug, $filters);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($odd) => new SportFuturesOddResource($odd, $context))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => $this->meta($context->slug, $filters, [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]),
        ]);
    }

    public function teamIndex(
        string $sport,
        string $team,
        SportFuturesOddIndexRequest $request,
        SportContextResolver $sports,
        SportFuturesOddQuery $futures,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters() + ['team_id' => (int) $team];
        $paginator = $futures->paginate($context->slug, $filters);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($odd) => new SportFuturesOddResource($odd, $context))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => $this->meta($context->slug, $filters, [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]) + ['team_id' => (int) $team],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $pagination
     * @return array<string, mixed>
     */
    private function meta(string $sport, array $filters, array $pagination): array
    {
        return [
            'version' => 'v2',
            'sport' => $sport,
            'contract' => 'sports.markets.futures.index',
            'filters' => $filters,
            'pagination' => $pagination,
            'tier' => [
                'mode' => 'sanitized_default',
                'allowed_field_groups' => ['identity', 'market', 'outcome', 'entity', 'freshness'],
                'withheld_field_groups' => ['raw_payload'],
            ],
            'freshness' => [],
            'warnings' => [],
        ];
    }
}
