<?php

namespace App\Http\Controllers\NFL;

use App\Http\Controllers\Controller;
use App\Http\Resources\NFL\TeamResource;
use App\Models\NFL\Team;
use Inertia\Response;

class TeamController extends Controller
{
    public function __invoke(Team $team): Response
    {
        return $this->renderResourcePage('NFL/Team', 'team', $team, TeamResource::class);
    }
}
