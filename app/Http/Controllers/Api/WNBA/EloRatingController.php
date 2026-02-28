<?php

namespace App\Http\Controllers\Api\WNBA;

use App\Http\Controllers\Api\Sports\AbstractEloRatingController;
use App\Http\Resources\WNBA\EloRatingResource;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Team;

class EloRatingController extends AbstractEloRatingController
{
    protected const ELO_RATING_MODEL = EloRating::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_RESOURCE = EloRatingResource::class;

    protected function getDefaultOrderColumns(): array
    {
        return ['season', 'week'];
    }

    protected function getBySeasonOrderColumns(): array
    {
        return ['week'];
    }
}
