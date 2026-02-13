<?php

namespace App\Http\Controllers\NFL;

use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\TeamResource;
use App\Models\NFL\Team;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Team $team): Response
    {
        return Inertia::render('NFL/Team', [
            'team' => (new TeamResource($team))->resolve(),
        ]);
    }
}
