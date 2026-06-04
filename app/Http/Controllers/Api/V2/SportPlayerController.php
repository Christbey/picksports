<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportPlayerIndexRequest;
use App\Http\Resources\Api\V2\SportPlayerResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPlayerQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportPlayerController extends Controller
{
    public function index(
        string $sport,
        SportPlayerIndexRequest $request,
        SportContextResolver $sports,
        SportPlayerQuery $players,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $players->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($player) => new SportPlayerResource($player, $context))
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
        string $player,
        Request $request,
        SportContextResolver $sports,
        SportPlayerQuery $players,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $resolvedPlayer = $players->find($context, $player, $request->user());

        return response()->json([
            'data' => new SportPlayerResource($resolvedPlayer, $context),
            'meta' => [
                'sport' => $context->slug,
                'freshness' => [],
                'warnings' => [],
            ],
        ]);
    }

    public function teamIndex(
        string $sport,
        string $team,
        SportPlayerIndexRequest $request,
        SportContextResolver $sports,
        SportPlayerQuery $players,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $players->paginateForTeam($context, $team, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($player) => new SportPlayerResource($player, $context))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => [
                'sport' => $context->slug,
                'team_id' => (int) $team,
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
}
