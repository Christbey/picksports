<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncGames;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = \App\Models\WCBB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WCBB\Team::class;
    protected const UNIQUE_GAME_KEY = 'espn_id';
}
