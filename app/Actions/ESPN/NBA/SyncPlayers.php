<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncPlayers;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = \App\Models\NBA\Player::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected const PLAYER_DTO_CLASS = \App\DataTransferObjects\ESPN\PlayerData::class;
}
