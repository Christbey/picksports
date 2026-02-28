<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncPlayers;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = \App\Models\MLB\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\MLB\Team::class;

    protected const PLAYER_DTO_CLASS = \App\DataTransferObjects\ESPN\PlayerData::class;

    protected const ATHLETES_NESTED_UNDER_GROUP_ITEMS = true;
}
