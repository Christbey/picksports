<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncPlayers;
use App\DataTransferObjects\ESPN\PlayerData;
use App\Models\WNBA\Player;
use App\Models\WNBA\Team;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_DTO_CLASS = PlayerData::class;

    protected const ATHLETES_NESTED_UNDER_GROUP_ITEMS = false;
}
