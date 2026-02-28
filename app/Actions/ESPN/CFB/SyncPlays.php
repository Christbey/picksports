<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlays;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;

    protected const PLAY_MODEL_CLASS = \App\Models\CFB\Play::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const PLAY_DTO_CLASS = \App\DataTransferObjects\ESPN\FootballPlayData::class;
}
