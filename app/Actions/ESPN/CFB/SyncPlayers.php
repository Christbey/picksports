<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlayers;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = \App\Models\CFB\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const PLAYER_DTO_CLASS = \App\DataTransferObjects\ESPN\CollegePlayerData::class;

    protected const ATHLETES_NESTED_UNDER_GROUP_ITEMS = true;
}
