<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncPlays;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = \App\Models\WCBB\Game::class;

    protected const PLAY_MODEL_CLASS = \App\Models\WCBB\Play::class;

    protected const TEAM_MODEL_CLASS = \App\Models\WCBB\Team::class;

    protected const PLAY_DTO_CLASS = \App\DataTransferObjects\ESPN\BasketballPlayData::class;
}
