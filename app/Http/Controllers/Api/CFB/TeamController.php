<?php

namespace App\Http\Controllers\Api\CFB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CFB\TeamResource;
use App\Models\CFB\Team;

class TeamController extends Controller
{
    /**
     * Display a listing of CFB teams.
     */
    public function index()
    {
        $teams = Team::query()
            ->orderBy('school')
            ->paginate(15);

        return TeamResource::collection($teams);
    }

    /**
     * Display the specified CFB team.
     */
    public function show(Team $team)
    {
        return new TeamResource($team);
    }
}
