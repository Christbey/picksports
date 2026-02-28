<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncPlayers;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = \App\Models\WCBB\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WCBB\Team::class;

    protected const PLAYER_DTO_CLASS = \App\DataTransferObjects\ESPN\CollegePlayerData::class;
}
