<?php

namespace App\Http\Controllers\WNBA;

use App\Http\Controllers\Controller;
use App\Models\WNBA\Team;
use Inertia\Response;

class TeamController extends Controller
{
    public function __invoke(Team $team): Response
    {
        return $this->renderIdPage('WNBA/Team', 'teamId', $team->id);
    }
}
