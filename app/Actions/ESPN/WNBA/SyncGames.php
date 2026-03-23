<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncGames;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UNIQUE_GAME_KEY = 'espn_id';
}
