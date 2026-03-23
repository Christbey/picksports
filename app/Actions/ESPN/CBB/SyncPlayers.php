<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlayers;
use App\DataTransferObjects\ESPN\CollegePlayerData;
use App\Models\CBB\Player;
use App\Models\CBB\Team;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_DTO_CLASS = CollegePlayerData::class;
}
