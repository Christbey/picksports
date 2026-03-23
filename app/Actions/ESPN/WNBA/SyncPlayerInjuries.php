<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\WNBA\Player;
use App\Models\WNBA\Team;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'wnba_player_injuries';
}
