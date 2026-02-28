<?php

namespace App\Http\Controllers\Api\NBA;

use App\Http\Controllers\Api\Sports\AbstractEloRatingController;
use App\Http\Resources\NBA\EloRatingResource;
use App\Models\NBA\EloRating;
use App\Models\NBA\Team;

class EloRatingController extends AbstractEloRatingController
{
    protected const ELO_RATING_MODEL = EloRating::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_RESOURCE = EloRatingResource::class;
}
