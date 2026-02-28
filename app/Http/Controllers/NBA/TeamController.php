<?php

namespace App\Http\Controllers\NBA;

use App\Http\Controllers\Controller;
use App\Http\Resources\NBA\TeamResource;
use App\Models\NBA\Team;
use Inertia\Response;

class TeamController extends Controller
{
    public function __invoke(Team $team): Response
    {
        return $this->renderResourcePage('NBA/Team', 'team', $team, TeamResource::class);
    }
}
