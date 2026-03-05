<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = \App\Models\MLB\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\MLB\Team::class;

    protected const INJURY_TABLE = 'mlb_player_injuries';
}
