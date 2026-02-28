<?php

namespace App\Http\Controllers\Api\WCBB;

use App\Http\Controllers\Api\Sports\AbstractEloRatingController;
use App\Http\Resources\WCBB\EloRatingResource;
use App\Models\WCBB\EloRating;
use App\Models\WCBB\Team;

class EloRatingController extends AbstractEloRatingController
{
    protected const ELO_RATING_MODEL = EloRating::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_RESOURCE = EloRatingResource::class;

    protected function getDefaultOrderColumns(): array
    {
        return ['game_date'];
    }
}
