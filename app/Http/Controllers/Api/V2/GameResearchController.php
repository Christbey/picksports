<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\Api\V2\GameAnalysisAccess;
use App\Services\Api\V2\GameResearchPresentation;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Predictions\Review\CurrentPredictionReview;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Research\GameResearchService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameResearchController extends Controller
{
    public function __invoke(
        string $sport,
        string $game,
        Request $request,
        SportContextResolver $sports,
        SportGameQuery $games,
        GameResearchService $research,
        CurrentPredictionReview $reviews,
        GameResearchPresentation $presentation,
        PredictionReviewCoordinator $coordinator,
        SportsDateWindowService $dates,
        GameAnalysisAccess $access,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        // Match the existing NFL research disclosure requirements for every sport.
        // Admin-only middleware is retained independently of subscription bypasses.
        $access->authorize($request->user(), $context->slug);
        $resolved = $games->find($context, $game, $request->user(), 'identity');
        $data = $presentation->present($research->forGame($resolved, $context->slug), $reviews->forGame($resolved, $context->slug));
        $start = $resolved->sportEvent?->starts_at ?? $dates->gameDateTimeUtc($resolved->game_date, $resolved->game_time);
        $data['capabilities'] = ['can_retry' => in_array($resolved->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
            && ($start?->isFuture() ?? false)
            && ($research->enabled($context->slug) || $coordinator->enabled($context->slug))];

        return response()->json([
            'data' => $data,
            'meta' => ['version' => 'v2', 'sport' => $context->slug, 'contract' => 'sports.games.analysis.research.show'],
        ]);
    }
}
