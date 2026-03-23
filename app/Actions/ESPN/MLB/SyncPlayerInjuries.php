<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\MLB\Player;
use App\Models\MLB\Team;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'mlb_player_injuries';
}
