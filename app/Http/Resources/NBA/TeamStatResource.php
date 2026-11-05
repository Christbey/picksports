<?php

namespace App\Http\Resources\NBA;

use App\Http\Resources\Sports\AbstractBasketballTeamStatResource;

class TeamStatResource extends AbstractBasketballTeamStatResource
{
    protected const TEAM_RESOURCE = TeamResource::class;

    protected const GAME_RESOURCE = GameResource::class;
}
