<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportPlayerPropIndexRequest;
use App\Http\Resources\Api\V2\SportPlayerPropResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPlayerPropQuery;
use Illuminate\Http\JsonResponse;

class SportPlayerPropController extends Controller
{
    public function index(
        string $sport,
        SportPlayerPropIndexRequest $request,
        SportContextResolver $sports,
        SportPlayerPropQuery $props,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $props->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($prop) => new SportPlayerPropResource($prop, $context))
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

    public function playerIndex(
        string $sport,
        string $player,
        SportPlayerPropIndexRequest $request,
        SportContextResolver $sports,
        SportPlayerPropQuery $props,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters() + ['player_id' => (int) $player];
        $paginator = $props->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($prop) => new SportPlayerPropResource($prop, $context))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => $this->meta($context->slug, $filters, [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]) + ['player_id' => (int) $player],
        ]);
    }

    public function gameIndex(
        string $sport,
        string $game,
        SportPlayerPropIndexRequest $request,
        SportContextResolver $sports,
        SportPlayerPropQuery $props,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters() + ['game_id' => (int) $game];
        $paginator = $props->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($prop) => new SportPlayerPropResource($prop, $context))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => $this->meta($context->slug, $filters, [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]) + ['game_id' => (int) $game],
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
            'contract' => 'sports.markets.player-props.index',
            'filters' => $filters,
            'pagination' => $pagination,
            'tier' => [
                'mode' => 'sanitized_default',
                'allowed_field_groups' => ['identity', 'market', 'odds', 'recommendation', 'grading', 'freshness'],
                'withheld_field_groups' => ['raw_data', 'narrative', 'ai_analysis'],
            ],
            'freshness' => [],
            'warnings' => [],
        ];
    }
}
