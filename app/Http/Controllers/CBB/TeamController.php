<?php

namespace App\Http\Controllers\CBB;

use App\Http\Controllers\Controller;
use App\Models\CBB\Team;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Team $team): Response
    {
        return Inertia::render('CBB/Team', [
            'teamId' => $team->id,
        ]);
    }
}
