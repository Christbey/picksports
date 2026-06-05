<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportPredictionIndexRequest;
use App\Http\Resources\Api\V2\SportPredictionResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportPredictionController extends Controller
{
    public function index(
        string $sport,
        SportPredictionIndexRequest $request,
        SportContextResolver $sports,
        SportPredictionQuery $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $predictions->paginate($context, $filters, $request->user());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($prediction) => new SportPredictionResource($prediction, $context))
        );

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => $this->collectionMeta($context->slug, $filters, [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]),
        ]);
    }

    public function show(
        string $sport,
        string $prediction,
        Request $request,
        SportContextResolver $sports,
        SportPredictionQuery $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $resolvedPrediction = $predictions->find($context, $prediction, $request->user());

        return response()->json([
            'data' => new SportPredictionResource($resolvedPrediction, $context),
            'meta' => $this->itemMeta($context->slug),
        ]);
    }

    public function availableSeasons(
        string $sport,
        Request $request,
        SportContextResolver $sports,
        SportPredictionQuery $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);

        return response()->json([
            'data' => $predictions->availableSeasons($context, $request->user()),
            'meta' => $this->filterMeta($context->slug, 'sports.predictions.available-seasons'),
        ]);
    }

    public function availableDates(
        string $sport,
        SportPredictionIndexRequest $request,
        SportContextResolver $sports,
        SportPredictionQuery $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();

        return response()->json([
            'data' => $predictions->availableDates($context, $filters, $request->user()),
            'meta' => $this->filterMeta($context->slug, 'sports.predictions.available-dates', $filters),
        ]);
    }

    public function gamePrediction(
        string $sport,
        string $game,
        Request $request,
        SportContextResolver $sports,
        SportPredictionQuery $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $resolvedPrediction = $predictions->findForGame($context, $game, $request->user());

        return response()->json([
            'data' => new SportPredictionResource($resolvedPrediction, $context),
            'meta' => $this->itemMeta($context->slug) + ['game_id' => (int) $game],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $pagination
     * @return array<string, mixed>
     */
    private function collectionMeta(string $sport, array $filters, array $pagination): array
    {
        return [
            'version' => 'v2',
            'sport' => $sport,
            'contract' => 'sports.predictions.index',
            'filters' => $filters,
            'pagination' => $pagination,
            'tier' => $this->tierMeta(),
            'freshness' => [],
            'warnings' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemMeta(string $sport): array
    {
        return [
            'version' => 'v2',
            'sport' => $sport,
            'contract' => 'sports.predictions.show',
            'tier' => $this->tierMeta(),
            'freshness' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function filterMeta(string $sport, string $contract, array $filters = []): array
    {
        return [
            'version' => 'v2',
            'sport' => $sport,
            'contract' => $contract,
            'filters' => $filters,
            'tier' => $this->tierMeta(),
            'freshness' => [],
            'warnings' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tierMeta(): array
    {
        return [
            'mode' => 'sanitized_default',
            'allowed_field_groups' => ['identity', 'game', 'pick', 'projection', 'grading', 'live_state', 'depth_chart_context', 'market_summary', 'timestamps'],
            'withheld_field_groups' => ['model', 'betting_value', 'ai_analysis', 'narrative', 'raw_inputs'],
        ];
    }
}
