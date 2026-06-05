<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportGameIndexRequest;
use App\Http\Resources\Api\V2\SportGameResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Sports\GameMatchupContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportGameController extends Controller
{
    public function index(
        string $sport,
        SportGameIndexRequest $request,
        SportContextResolver $sports,
        SportGameQuery $games,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $games->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($game) => new SportGameResource($game, $context))
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
        string $game,
        Request $request,
        SportContextResolver $sports,
        SportGameQuery $games,
        GameMatchupContextService $matchupContext,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $resolvedGame = $games->find($context, $game, $request->user());
        $resolvedGame->setAttribute('matchup_context', $matchupContext->forGame($resolvedGame));

        return response()->json([
            'data' => new SportGameResource($resolvedGame, $context),
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
        SportGameIndexRequest $request,
        SportContextResolver $sports,
        SportGameQuery $games,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = array_merge($request->validatedFilters(), [
            'team_id' => (int) $team,
        ]);
        $paginator = $games->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($game) => new SportGameResource($game, $context))
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
