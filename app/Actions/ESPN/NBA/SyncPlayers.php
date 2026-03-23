<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncPlayers;
use App\DataTransferObjects\ESPN\PlayerData;
use App\Models\NBA\Player;
use App\Models\NBA\Team;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_DTO_CLASS = PlayerData::class;
}
