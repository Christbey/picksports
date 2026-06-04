<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportTeamMetricIndexRequest;
use App\Http\Resources\Api\V2\SportTeamMetricResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportTeamMetricQuery;
use Illuminate\Http\JsonResponse;

class SportTeamMetricController extends Controller
{
    public function index(
        string $sport,
        SportTeamMetricIndexRequest $request,
        SportContextResolver $sports,
        SportTeamMetricQuery $metrics,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $metrics->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($metric) => new SportTeamMetricResource($metric, $context))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => $this->meta($context->slug, 'sports.metrics.teams.index', $filters) + [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    public function availableSeasons(
        string $sport,
        SportTeamMetricIndexRequest $request,
        SportContextResolver $sports,
        SportTeamMetricQuery $metrics,
    ): JsonResponse {
        $context = $sports->resolve($sport);

        return response()->json([
            'data' => $metrics->availableSeasons($context, $request->user()),
            'meta' => $this->meta($context->slug, 'sports.metrics.teams.available-seasons'),
        ]);
    }

    public function teamShow(
        string $sport,
        string $team,
        SportTeamMetricIndexRequest $request,
        SportContextResolver $sports,
        SportTeamMetricQuery $metrics,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $metric = $metrics->latestForTeam($context, $team, $filters, $request->user());

        return response()->json([
            'data' => new SportTeamMetricResource($metric, $context),
            'meta' => $this->meta($context->slug, 'sports.teams.metrics.show', $filters),
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
