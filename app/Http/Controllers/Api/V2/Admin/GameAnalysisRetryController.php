<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Predictions\RefreshGameAnalysis;
use App\Services\Api\V2\GameAnalysisAccess;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Predictions\Review\PredictionReviewCoordinator;
use App\Services\Research\GameResearchService;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameAnalysisRetryController extends Controller
{
    public function __invoke(string $sport, string $game, Request $request, SportContextResolver $sports, SportGameQuery $games, GameResearchService $research, PredictionReviewCoordinator $reviews, SportsDateWindowService $dates, GameAnalysisAccess $access): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $context = $sports->resolve($sport);
        $access->authorize($request->user(), $context->slug);
        $resolved = $games->find($context, $game, $request->user(), 'identity');
        $start = $resolved->sportEvent?->starts_at
            ?? $dates->gameDateTimeUtc($resolved->game_date, $resolved->game_time);
        abort_unless(($research->enabled($context->slug) || $reviews->enabled($context->slug))
            && $start?->isFuture() && in_array($resolved->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true),
            422, 'Game analysis refresh is available only for enabled pregame games.');

        RefreshGameAnalysis::dispatch($context->slug, (int) $resolved->getKey(), (int) $request->user()->getKey());

        return response()->json(['data' => ['status' => 'queued', 'game_id' => $resolved->getKey(), 'sport' => $context->slug],
            'message' => 'Game analysis refresh queued.'], 202);
    }
}
