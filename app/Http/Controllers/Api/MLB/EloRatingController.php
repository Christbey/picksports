<?php

namespace App\Http\Controllers\Api\MLB;

use App\Http\Controllers\Api\Sports\AbstractEloRatingController;
use App\Http\Resources\MLB\EloRatingResource;
use App\Models\MLB\EloRating;
use App\Models\MLB\Team;

class EloRatingController extends AbstractEloRatingController
{
    protected const ELO_RATING_MODEL = EloRating::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_RESOURCE = EloRatingResource::class;
}
