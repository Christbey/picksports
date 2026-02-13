<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\WCBB\TeamResource;
use App\Models\WCBB\Team;

class TeamController extends Controller
{
    /**
     * Display a listing of WCBB teams.
     */
    public function index()
    {
        $teams = Team::query()
            ->orderBy('display_name')
            ->paginate(15);

        return TeamResource::collection($teams);
    }

    /**
     * Display the specified WCBB team.
     */
    public function show(Team $team)
    {
        return new TeamResource($team);
    }
}
