<?php

namespace App\Http\Controllers\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\TeamResource;
use App\Models\NBA\Team;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Team $team): Response
    {
        return Inertia::render('NBA/Team', [
            'team' => (new TeamResource($team))->resolve(),
        ]);
    }
}
