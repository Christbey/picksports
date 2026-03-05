<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = \App\Models\NFL\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NFL\Team::class;

    protected const INJURY_TABLE = 'nfl_player_injuries';
}
