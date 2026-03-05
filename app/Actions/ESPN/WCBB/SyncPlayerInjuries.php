<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = \App\Models\WCBB\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WCBB\Team::class;

    protected const INJURY_TABLE = 'wcbb_player_injuries';
}
