<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportPredictionIndexRequest;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportPredictionController extends Controller
{
    public function index(
        string $sport,
        SportPredictionIndexRequest $request,
        SportContextResolver $sports,
        SportPredictionReader $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();
        $paginator = $predictions->queryFor($context)->paginate($context, $filters, $request->user());
        $paginator->setCollection($predictions->resources($context, $paginator->getCollection()));

        return response()->json([
            'data' => $paginator->getCollection()->values(),
            'meta' => $this->collectionMeta($context->slug, $filters, [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ]) + $predictions->sourceMeta($context),
        ]);
    }

    public function show(
        string $sport,
        string $prediction,
        Request $request,
        SportContextResolver $sports,
        SportPredictionReader $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $resolvedPrediction = $predictions->queryFor($context)->find($context, $prediction, $request->user());

        return response()->json([
            'data' => $predictions->resource($context, $resolvedPrediction),
            'meta' => $this->itemMeta($context->slug) + $predictions->sourceMeta($context),
        ]);
    }

    public function availableSeasons(
        string $sport,
        Request $request,
        SportContextResolver $sports,
        SportPredictionReader $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);

        return response()->json([
            'data' => $predictions->queryFor($context)->availableSeasons($context, $request->user()),
            'meta' => $this->filterMeta($context->slug, 'sports.predictions.available-seasons')
                + $predictions->sourceMeta($context),
        ]);
    }

    public function availableDates(
        string $sport,
        SportPredictionIndexRequest $request,
        SportContextResolver $sports,
        SportPredictionReader $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();

        return response()->json([
            'data' => $predictions->queryFor($context)->availableDates($context, $filters, $request->user()),
            'meta' => $this->filterMeta($context->slug, 'sports.predictions.available-dates', $filters)
                + $predictions->sourceMeta($context),
        ]);
    }

    public function gamePrediction(
        string $sport,
        string $game,
        Request $request,
        SportContextResolver $sports,
        SportPredictionReader $predictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $payload = $predictions->forGame($context, $game, $request);

        return response()->json([
            'data' => $payload['data'],
            'meta' => $this->itemMeta($context->slug) + $payload['meta'],
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
            'allowed_field_groups' => ['identity', 'game', 'pick', 'projection', 'grading', 'live_state', 'depth_chart_context', 'market_summary', 'sport_signal_context', 'timestamps'],
            'withheld_field_groups' => ['model', 'betting_value', 'ai_analysis', 'narrative', 'raw_inputs'],
        ];
    }
}
