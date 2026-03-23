<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\CFB\Player;
use App\Models\CFB\Team;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'cfb_player_injuries';
}
