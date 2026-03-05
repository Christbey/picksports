<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = \App\Models\NBA\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected const INJURY_TABLE = 'nba_player_injuries';
}
