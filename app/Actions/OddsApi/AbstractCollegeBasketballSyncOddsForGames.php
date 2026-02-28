<?php

namespace App\Actions\OddsApi;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractCollegeBasketballSyncOddsForGames extends AbstractSyncOddsForGames
{
    protected function matchThreshold(): float
    {
        return 85.0;
    }

    protected function homeTeamNames(Model $game): array
    {
        return $this->schoolMascotAbbreviationTeamNames($game->homeTeam);
    }

    protected function awayTeamNames(Model $game): array
    {
        return $this->schoolMascotAbbreviationTeamNames($game->awayTeam);
    }
}
