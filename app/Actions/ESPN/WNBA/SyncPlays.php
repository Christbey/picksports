<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncPlays;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = \App\Models\WNBA\Game::class;

    protected const PLAY_MODEL_CLASS = \App\Models\WNBA\Play::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WNBA\Team::class;

    protected const PLAY_DTO_CLASS = \App\DataTransferObjects\ESPN\BasketballPlayData::class;

    protected const GAME_LOOKUP_COLUMN = 'espn_id';
}
