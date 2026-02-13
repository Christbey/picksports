<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\TeamStatResource;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamStat;

class TeamStatController extends Controller
{
    /**
     * Display a listing of WCBB team stats.
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
     * Display the specified WCBB team stat.
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
