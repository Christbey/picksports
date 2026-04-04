<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncGames;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

class SyncGames extends AbstractSyncGames
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UNIQUE_GAME_KEY = 'espn_event_id';
}
