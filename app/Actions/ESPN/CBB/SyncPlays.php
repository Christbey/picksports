<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlays;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;

    protected const PLAY_MODEL_CLASS = \App\Models\CBB\Play::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CBB\Team::class;

    protected const PLAY_DTO_CLASS = \App\DataTransferObjects\ESPN\BasketballPlayData::class;

    protected const USE_GAME_PLAYS_PAYLOAD = true;
}
