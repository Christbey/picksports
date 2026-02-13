<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CFB\TeamStatResource;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\CFB\TeamStat;

class TeamStatController extends Controller
{
    /**
     * Display a listing of CFB team stats.
     */
    public function index()
    {
        $stats = TeamStat::query()
            ->with(['team', 'game'])
            ->orderByDesc('id')
            ->paginate(15);

        return TeamStatResource::collection($stats);
    }

    /**
     * Display the specified CFB team stat.
     */
    public function show(TeamStat $teamStat)
    {
        $teamStat->load(['team', 'game']);

        return new TeamStatResource($teamStat);
    }

    /**
     * Display team stats for a specific game.
     */
    public function byGame(Game $game)
    {
        $stats = TeamStat::query()
            ->with(['team'])
            ->where('game_id', $game->id)
            ->paginate(15);

        return TeamStatResource::collection($stats);
    }

    /**
     * Display stats for a specific team.
     */
    public function byTeam(Team $team)
    {
        $stats = TeamStat::query()
            ->with(['game'])
            ->where('team_id', $team->id)
            ->orderByDesc('id')
            ->paginate(15);

        return TeamStatResource::collection($stats);
    }
}
