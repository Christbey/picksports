<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\SportTeamTrendRequest;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Api\V2\SportGameQuery;
use App\Services\Api\V2\SportTeamTrendQuery;
use App\Support\MLB\MlbGameStart;
use Illuminate\Http\JsonResponse;

class SportGameTrendController extends Controller
{
    public function __invoke(
        string $sport,
        string $game,
        SportTeamTrendRequest $request,
        SportContextResolver $sports,
        SportGameQuery $games,
        SportTeamTrendQuery $trends,
    ): JsonResponse {
        $context = $sports->resolve($sport);
        abort_unless($context->slug === 'mlb', 404, 'Game matchup trends are currently available for MLB.');
        $resolvedGame = $games->find($context, $game, $request->user(), 'identity');
        $filters = [
            'games' => $request->validatedFilters()['games'] ?? 'season',
            'season' => (int) $resolvedGame->getAttribute('season'),
            'season_type' => (string) $resolvedGame->getAttribute('season_type'),
            'before_date' => MlbGameStart::for($resolvedGame)?->toIso8601String()
                ?? $resolvedGame->getAttribute('game_date')?->toDateString(),
        ];

        $homeTeamId = (int) $resolvedGame->getAttribute('home_team_id');
        $awayTeamId = (int) $resolvedGame->getAttribute('away_team_id');

        return response()->json([
            'data' => [
                'home' => $trends->get($context, $homeTeamId, $filters, $request->user()),
                'away' => $trends->get($context, $awayTeamId, $filters, $request->user()),
            ],
            'meta' => [
                'version' => 'v2',
                'sport' => $context->slug,
                'contract' => 'sports.games.trends.show',
                'game_id' => (int) $resolvedGame->getKey(),
                'filters' => $filters,
                'tier' => [],
                'freshness' => [],
                'warnings' => [],
            ],
        ]);
    }
}
