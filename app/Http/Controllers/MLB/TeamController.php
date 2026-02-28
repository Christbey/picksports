<?php

namespace App\Http\Controllers\MLB;

use App\Http\Controllers\Controller;
use App\Models\MLB\Team;
use Inertia\Response;

class TeamController extends Controller
{
    public function __invoke(Team $team): Response
    {
        return $this->renderIdPage('MLB/Team', 'teamId', $team->id);
    }
}
