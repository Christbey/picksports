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

    protected function homeTeamNames(Model $game): array
    {
        return $this->schoolIdentityTeamNames($game->homeTeam);
    }

    protected function awayTeamNames(Model $game): array
    {
        return $this->schoolIdentityTeamNames($game->awayTeam);
    }

    /**
     * @return list<string>
     */
    private function schoolIdentityTeamNames(mixed $team): array
    {
        $school = trim((string) ($team->school ?? ''));

        if ($school === '') {
            return [];
        }

        return array_values(array_unique([
            $school,
            trim($school.' '.($team->mascot ?? '')),
        ]));
    }
}
