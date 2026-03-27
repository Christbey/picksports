<?php

namespace App\Http\Controllers\Api\Sports;

use App\Services\Sports\DepthChartDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class AbstractDepthChartController extends AbstractSportsApiController
{
    protected const SPORT_KEY = '';

    protected const TEAM_MODEL = '';

    protected const GAME_MODEL = '';

    protected const PLAYER_STAT_MODEL = '';

    protected const DEPTH_CHART_ENTRY_MODEL = '';

    public function byTeam($team, Request $request): JsonResponse
    {
        $teamId = $this->requireNumericId($team);

        $payload = app(DepthChartDataService::class)->forTeam(
            sport: static::SPORT_KEY,
            teamModel: static::TEAM_MODEL,
            depthChartEntryModel: static::DEPTH_CHART_ENTRY_MODEL,
            playerStatModel: static::PLAYER_STAT_MODEL,
            gameModel: static::GAME_MODEL,
            teamId: $teamId,
            season: $request->integer('season') ?: null,
            seasonType: $request->query('season_type'),
            beforeDate: $request->string('before_date')->toString() ?: null,
        );

        return response()->json(['data' => $payload]);
    }

    public function byGame($game): JsonResponse
    {
        $gameId = $this->requireNumericId($game);

        $payload = app(DepthChartDataService::class)->forGame(
            sport: static::SPORT_KEY,
            gameModel: static::GAME_MODEL,
            depthChartEntryModel: static::DEPTH_CHART_ENTRY_MODEL,
            playerStatModel: static::PLAYER_STAT_MODEL,
            gameId: $gameId,
        );

        return response()->json(['data' => $payload]);
    }
}
