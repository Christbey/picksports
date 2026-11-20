<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V2\SportGameResource;
use App\Services\Api\V2\MlbGamePageData;
use App\Services\Api\V2\NflGamePageQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Api\V2\SportPredictionReader;
use App\Services\Api\V2\SportTeamMetricQuery;
use App\Services\Predictions\HistoricalForecastArchive;
use App\Services\Sports\GameMatchupContextService;
use App\Support\MLB\MlbGameStart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportGamePageController extends Controller
{
    public function __invoke(
        string $sport,
        string $game,
        Request $request,
        SportContextResolver $sports,
        SportGameQuery $games,
        SportPredictionReader $predictions,
        SportTeamMetricQuery $metrics,
        GameMatchupContextService $matchupContext,
        NflGamePageQuery $nflPage,
        MlbGamePageData $page,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        if ($context->slug === 'nfl') {
            return response()->json($nflPage->get($context, $game, $request));
        }
        abort_unless($context->slug === 'mlb', 404, 'The composite game page is currently available for MLB and NFL.');

        $resolvedGame = $games->find($context, $game, $request->user(), 'page');
        $page->hydrateStartingPitchers($resolvedGame, $context->models['player']);
        $preparedMatchup = $matchupContext->forGame($resolvedGame);
        $gameStart = MlbGameStart::for($resolvedGame)?->toIso8601String()
            ?? $resolvedGame->getAttribute('game_date')?->toDateString();
        $recentFilters = [
            'status' => 'STATUS_FINAL',
            'season' => (int) $resolvedGame->getAttribute('season'),
            'before_game_at' => $gameStart,
            'exclude_game_id' => (int) $resolvedGame->getKey(),
        ];

        $homeTeamId = (int) $resolvedGame->getAttribute('home_team_id');
        $awayTeamId = (int) $resolvedGame->getAttribute('away_team_id');
        $metricFilters = [
            'season' => (int) $resolvedGame->getAttribute('season'),
            'season_type' => (string) $resolvedGame->getAttribute('season_type'),
        ];

        $predictionPayload = $predictions->forLoadedGame($context, $resolvedGame);
        $recentGames = $page->recentGames(
            $context,
            $games,
            $recentFilters,
            $homeTeamId,
            $awayTeamId,
            $request,
        );

        return response()->json([
            'data' => [
                'game' => (new SportGameResource($resolvedGame, $context, $preparedMatchup))->resolve($request),
                'prediction' => isset($predictionPayload['data']) ? $predictionPayload['data']->resolve($request) : null,
                'historical_prediction' => app(HistoricalForecastArchive::class)->forGame($resolvedGame, 'mlb'),
                'recent_games' => $recentGames,
                'metrics' => [
                    'home' => $page->teamMetric($context, $metrics, $homeTeamId, $metricFilters, $request),
                    'away' => $page->teamMetric($context, $metrics, $awayTeamId, $metricFilters, $request),
                ],
                'depth_charts_available' => $page->depthChartsAvailable(
                    $context->models['depth_chart_entry'],
                    [$homeTeamId, $awayTeamId],
                    (int) $resolvedGame->getAttribute('season'),
                ),
            ],
            'meta' => [
                'version' => 'v2',
                'contract' => 'sports.games.page.show',
                'sport' => $context->slug,
                'game_id' => (int) $resolvedGame->getKey(),
                'prediction_source' => $predictionPayload['meta']['prediction_source'] ?? null,
                'deferred' => ['matchup_trends', 'depth_charts'],
            ],
        ]);
    }
}
