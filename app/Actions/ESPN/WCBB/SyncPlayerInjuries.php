<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\WCBB\Player;
use App\Models\WCBB\Team;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'wcbb_player_injuries';
}
