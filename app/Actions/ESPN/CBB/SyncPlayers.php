<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlayers;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = \App\Models\CBB\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CBB\Team::class;

    protected const PLAYER_DTO_CLASS = \App\DataTransferObjects\ESPN\CollegePlayerData::class;
}
