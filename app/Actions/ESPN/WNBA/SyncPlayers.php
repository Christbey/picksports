<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncPlayers;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = \App\Models\WNBA\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WNBA\Team::class;

    protected const PLAYER_DTO_CLASS = \App\DataTransferObjects\ESPN\PlayerData::class;

    protected const ATHLETES_NESTED_UNDER_GROUP_ITEMS = true;
}
