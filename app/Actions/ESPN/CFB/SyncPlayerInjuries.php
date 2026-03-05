<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = \App\Models\CFB\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const INJURY_TABLE = 'cfb_player_injuries';
}
