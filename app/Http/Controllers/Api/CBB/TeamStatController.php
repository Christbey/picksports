<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Api\Sports\AbstractTeamStatController;
use App\Http\Resources\CBB\TeamStatResource;
use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\CBB\TeamStat;
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

    public function seasonAverages(Team $team)
    {
        $data = $this->averagesService->forTeam(TeamStat::class, $team->id);

        if (! $data) {
            return response()->json(['data' => null], 404);
        }

        return response()->json([
            'data' => $data,
        ]);
    }
}
