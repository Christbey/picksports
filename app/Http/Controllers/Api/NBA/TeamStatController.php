<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\NBA\TeamStatResource;
use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamStat;
use App\Services\TeamStats\BasketballTeamSeasonAveragesService;

class TeamStatController extends AbstractTeamStatController
{
    protected const TEAM_STAT_MODEL = TeamStat::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const TEAM_STAT_RESOURCE = TeamStatResource::class;

    public function __construct(
        protected BasketballTeamSeasonAveragesService $averagesService
    ) {}

    public function allSeasonAverages()
    {
        return response()->json([
            'data' => $this->averagesService->allTeams(TeamStat::class),
        ]);
    }

    public function seasonAverages(Team $team)
    {
        $data = $this->averagesService->forTeam(
            TeamStat::class,
            $team->id,
            includeTeamId: true,
            includeFouls: true
        );

        if (! $data) {
            return response()->json(['data' => null], 404);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}
