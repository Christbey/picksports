<?php

namespace App\Actions\OddsApi\CFB;

use App\Actions\OddsApi\AbstractSyncOddsForGames;
use App\Models\CFB\Game;
use Illuminate\Database\Eloquent\Model;

class SyncOddsForGames extends AbstractSyncOddsForGames
{
    protected const SPORT_KEY = 'americanfootball_ncaaf';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const MATCH_THRESHOLD = 85.0;

    protected const INCLUDE_ABBREVIATION_IN_TEAM_NAMES = true;

    protected function homeTeamNames(Model $game): array
    {
        return $this->schoolMascotAbbreviationTeamNames($game->homeTeam);
    }

    protected function awayTeamNames(Model $game): array
    {
        return $this->schoolMascotAbbreviationTeamNames($game->awayTeam);
    }
}
