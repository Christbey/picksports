<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncPlays;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = \App\Models\NBA\Game::class;

    protected const PLAY_MODEL_CLASS = \App\Models\NBA\Play::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NBA\Team::class;

    protected const PLAY_DTO_CLASS = \App\DataTransferObjects\ESPN\BasketballPlayData::class;

    protected const USE_GAME_PLAYS_PAYLOAD = true;

    protected const SKIP_EMPTY_PLAY_ID = true;
}
