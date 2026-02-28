<?php

namespace App\Http\Controllers\CBB;

use App\Http\Controllers\Controller;
use App\Models\CBB\Team;
use Inertia\Response;

class TeamController extends Controller
{
    public function __invoke(Team $team): Response
    {
        return $this->renderIdPage('CBB/Team', 'teamId', $team->id);
    }
}
