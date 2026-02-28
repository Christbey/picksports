<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncGames;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = \App\Models\WNBA\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WNBA\Team::class;
    protected const UNIQUE_GAME_KEY = 'espn_id';
}
