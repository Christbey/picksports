<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = \App\Models\CBB\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CBB\Team::class;

    protected const INJURY_TABLE = 'cbb_player_injuries';
}
