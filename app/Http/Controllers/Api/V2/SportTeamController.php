<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportTeamIndexRequest;
use App\Http\Resources\Api\V2\SportTeamResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportTeamQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportTeamController extends Controller
{
    public function index(
        string $sport,
        SportTeamIndexRequest $request,
        SportContextResolver $sports,
        SportTeamQuery $teams,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $teams->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($team) => new SportTeamResource($team, $context))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'sport' => $context->slug,
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
            ],
        ]);
    }

    public function show(
        string $sport,
        string $team,
        Request $request,
        SportContextResolver $sports,
        SportTeamQuery $teams,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $resolvedTeam = $teams->find($context, $team, $request->user());

        return response()->json([
            'data' => new SportTeamResource($resolvedTeam, $context),
            'meta' => [
                'sport' => $context->slug,
                'freshness' => [],
                'warnings' => [],
            ],
        ]);
    }
}
