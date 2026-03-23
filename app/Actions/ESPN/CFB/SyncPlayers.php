<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlayers;
use App\DataTransferObjects\ESPN\CollegePlayerData;
use App\Models\CFB\Player;
use App\Models\CFB\Team;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_DTO_CLASS = CollegePlayerData::class;

    protected const ATHLETES_NESTED_UNDER_GROUP_ITEMS = true;
}
