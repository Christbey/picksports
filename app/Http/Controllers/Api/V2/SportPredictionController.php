<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportPredictionIndexRequest;
use App\Http\Resources\Api\V2\CanonicalSportPredictionResource;
use App\Http\Resources\Api\V2\SportPredictionResource;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPredictionPresentationService;
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
        SportPredictionPresentationService $presentations,
        CanonicalSportPredictionQuery $canonicalPredictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();

        if ($canonicalPredictions->supports($context)) {
            $paginator = $canonicalPredictions->paginate($context, $filters, $request->user());
            $paginator->setCollection(
                $paginator->getCollection()->map(fn ($prediction) => new CanonicalSportPredictionResource($prediction, $context))
            );

            return response()->json([
                'data' => $paginator->getCollection()->values(),
                'meta' => $this->collectionMeta($context->slug, $filters, [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ]) + ['prediction_source' => 'canonical'],
            ]);
        }

        $paginator = $predictions->paginate($context, $filters, $request->user());
        $presentationByPrediction = $presentations->forPredictions($context, $paginator->getCollection());

        $paginator->setCollection(
            $paginator->getCollection()->map(fn ($prediction) => new SportPredictionResource(
                $prediction,
                $context,
                $presentationByPrediction->get((int) $prediction->getKey()),
            ))
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
        SportPredictionPresentationService $presentations,
        CanonicalSportPredictionQuery $canonicalPredictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);

        if ($canonicalPredictions->supports($context)) {
            return response()->json([
                'data' => new CanonicalSportPredictionResource(
                    $canonicalPredictions->find($context, $prediction, $request->user()),
                    $context,
                ),
                'meta' => $this->itemMeta($context->slug) + ['prediction_source' => 'canonical'],
            ]);
        }

        $resolvedPrediction = $predictions->find($context, $prediction, $request->user());

        return response()->json([
            'data' => new SportPredictionResource(
                $resolvedPrediction,
                $context,
                $presentations->forPrediction($context, $resolvedPrediction),
            ),
            'meta' => $this->itemMeta($context->slug),
        ]);
    }

    public function availableSeasons(
        string $sport,
        Request $request,
        SportContextResolver $sports,
        SportPredictionQuery $predictions,
        CanonicalSportPredictionQuery $canonicalPredictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);

        if ($canonicalPredictions->supports($context)) {
            return response()->json([
                'data' => $canonicalPredictions->availableSeasons($context, $request->user()),
                'meta' => $this->filterMeta($context->slug, 'sports.predictions.available-seasons')
                    + ['prediction_source' => 'canonical'],
            ]);
        }

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
        CanonicalSportPredictionQuery $canonicalPredictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $filters = $request->validatedFilters();

        if ($canonicalPredictions->supports($context)) {
            return response()->json([
                'data' => $canonicalPredictions->availableDates($context, $filters, $request->user()),
                'meta' => $this->filterMeta($context->slug, 'sports.predictions.available-dates', $filters)
                    + ['prediction_source' => 'canonical'],
            ]);
        }

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
        SportPredictionPresentationService $presentations,
        CanonicalSportPredictionQuery $canonicalPredictions,
    ): JsonResponse {
        $context = $sports->resolve($sport);

        if ($canonicalPredictions->supports($context)) {
            return response()->json([
                'data' => new CanonicalSportPredictionResource(
                    $canonicalPredictions->findForGame($context, $game, $request->user()),
                    $context,
                ),
                'meta' => $this->itemMeta($context->slug) + [
                    'game_id' => (int) $game,
                    'prediction_source' => 'canonical',
                ],
            ]);
        }

        $resolvedPrediction = $predictions->findForGame($context, $game, $request->user());

        return response()->json([
            'data' => new SportPredictionResource(
                $resolvedPrediction,
                $context,
                $presentations->forPrediction($context, $resolvedPrediction),
            ),
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
            'allowed_field_groups' => ['identity', 'game', 'pick', 'projection', 'grading', 'live_state', 'depth_chart_context', 'market_summary', 'sport_signal_context', 'timestamps'],
            'withheld_field_groups' => ['model', 'betting_value', 'ai_analysis', 'narrative', 'raw_inputs'],
        ];
    }
}
