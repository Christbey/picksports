<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncGames;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;
}
