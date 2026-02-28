<?php

namespace App\Http\Controllers\WCBB;

use App\Http\Controllers\Controller;
use App\Models\WCBB\Team;
use Inertia\Response;

class TeamController extends Controller
{
    public function __invoke(Team $team): Response
    {
        return $this->renderIdPage('WCBB/Team', 'teamId', $team->id);
    }
}
