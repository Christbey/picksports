<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncGames;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CBB\Team::class;
    protected const UNIQUE_GAME_KEY = 'espn_id';
}
