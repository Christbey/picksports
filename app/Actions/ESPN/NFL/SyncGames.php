<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncGames;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = \App\Models\NFL\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NFL\Team::class;
    protected const UNIQUE_GAME_KEY = 'espn_id';
}
