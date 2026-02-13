<?php

namespace App\Http\Controllers\WCBB;

use App\Http\Controllers\Controller;
use App\Models\WCBB\Team;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Team $team): Response
    {
        return Inertia::render('WCBB/Team', [
            'team' => $team,
        ]);
    }
}
