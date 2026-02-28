<?php

namespace App\Http\Controllers\Api\NFL;

use App\Http\Controllers\Api\Sports\AbstractEloRatingController;
use App\Http\Resources\NFL\EloRatingResource;
use App\Models\NFL\EloRating;
use App\Models\NFL\Team;

class EloRatingController extends AbstractEloRatingController
{
    protected const ELO_RATING_MODEL = EloRating::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_RESOURCE = EloRatingResource::class;
}
