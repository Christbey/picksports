<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportPlayerPropIndexRequest;
use App\Http\Resources\Api\V2\SportPlayerPropResource;
use App\Http\Resources\BettingRecommendationResource;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportPlayerPropQuery;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportPlayerPropController extends Controller
{
    public function board(
        string $sport,
        Request $request,
        SportContextResolver $sports,
        PlayerPropAnalyzer $analyzer,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        $sportCode = strtoupper($context->slug);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'game' => ['nullable', 'integer'],
            'market' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:150'],
        ]);

        $resolvedDate = $this->resolveBoardDate($analyzer, $sportCode, $validated['date'] ?? null);
        $gameFilter = isset($validated['game']) ? (int) $validated['game'] : null;
        $marketFilter = $validated['market'] ?? null;
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : 75;

        $recommendations = $analyzer->precomputedRecommendations(
            sport: $sportCode,
            dateFilter: $resolvedDate,
            gameFilter: $gameFilter,
            marketFilter: $marketFilter,
            limit: $limit,
        );
        $diagnostics = $analyzer->precomputedRecommendationDiagnostics(
            sport: $sportCode,
            dateFilter: $resolvedDate,
            gameFilter: $gameFilter,
            marketFilter: $marketFilter,
        );

        return response()->json([
            'sport' => $sportCode,
            'data' => BettingRecommendationResource::collection($recommendations)->resolve(),
            'dates' => $analyzer->getAvailableDatesForSport($sportCode)->values(),
            'games' => $analyzer->getAvailableGamesForSport($sportCode, $resolvedDate)->values(),
            'markets' => $analyzer->getAvailableMarketsForSport($sportCode, $resolvedDate, $gameFilter)->values(),
            'filters' => [
                'date' => $resolvedDate,
                'game' => $gameFilter,
                'market' => $marketFilter,
            ],
            'meta' => [
                'version' => 'v2',
                'sport' => $context->slug,
                'contract' => 'sports.player-props.board',
                'tier' => [
                    'mode' => 'recommendation_board',
                    'allowed_field_groups' => ['identity', 'market', 'odds', 'recommendation', 'stats_summary', 'grading', 'freshness'],
                    'withheld_field_groups' => ['raw_data'],
                ],
                'freshness' => [],
                'diagnostics' => $diagnostics,
                'warnings' => $recommendations->isEmpty()
                    ? [$this->emptyBoardWarning($diagnostics)]
                    : [],
                'limit' => $limit,
                'source' => 'precomputed',
            ],
        ]);
    }

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

    private function resolveBoardDate(PlayerPropAnalyzer $analyzer, string $sportCode, ?string $requestedDate): ?string
    {
        if ($requestedDate !== null && $requestedDate !== '') {
            return $requestedDate;
        }

        $dates = $analyzer->getAvailableDatesForSport($sportCode);
        if ($dates->isEmpty()) {
            return $requestedDate;
        }

        $today = Carbon::today()->toDateString();
        $dateValues = $dates->pluck('value');

        if ($dateValues->contains($today)) {
            return $today;
        }

        $futureDates = $dateValues
            ->filter(fn ($date) => is_string($date) && $date > $today)
            ->values();

        if ($futureDates->isNotEmpty()) {
            return (string) $futureDates->first();
        }

        return $dateValues->isNotEmpty() ? (string) $dateValues->last() : null;
    }

    /**
     * @param  array<string, int>  $diagnostics
     */
    private function emptyBoardWarning(array $diagnostics): string
    {
        if (($diagnostics['raw_prop_count'] ?? 0) === 0) {
            return 'No synced player props were found for the selected filters. Run the sport player-prop sync before analysis.';
        }

        if (($diagnostics['analyzed_prop_count'] ?? 0) === 0) {
            return 'Player props are synced for the selected filters, but no analyzed recommendation snapshots were found. Run sports:analyze-player-props for this sport.';
        }

        if (($diagnostics['recommendation_candidate_count'] ?? 0) === 0) {
            return 'Player props are analyzed for the selected filters, but none currently meet the recommendation threshold.';
        }

        return 'Recommendation candidates exist for the selected filters, but none could be rendered by the board contract.';
    }
}
