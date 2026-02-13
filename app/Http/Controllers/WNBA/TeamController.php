<?php

namespace App\Http\Controllers\WNBA;

use App\Http\Controllers\Controller;
use App\Models\WNBA\Team;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Team $team): Response
    {
        return Inertia::render('WNBA/Team', [
            'team' => $team,
        ]);
    }
}
