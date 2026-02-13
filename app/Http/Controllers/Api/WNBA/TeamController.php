<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\WNBA\TeamResource;
use App\Models\WNBA\Team;

class TeamController extends Controller
{
    /**
     * Display a listing of WNBA teams.
     */
    public function index()
    {
        $teams = Team::query()
            ->orderBy('display_name')
            ->paginate(15);

        return TeamResource::collection($teams);
    }

    /**
     * Display the specified WNBA team.
     */
    public function show(Team $team)
    {
        return new TeamResource($team);
    }
}
