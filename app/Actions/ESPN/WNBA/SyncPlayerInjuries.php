<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = \App\Models\WNBA\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WNBA\Team::class;

    protected const INJURY_TABLE = 'wnba_player_injuries';
}
