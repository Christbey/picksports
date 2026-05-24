<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\BasketballPlayData;
use App\Models\WNBA\Game;
use App\Models\WNBA\Play;
use App\Models\WNBA\Team;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = BasketballPlayData::class;

    protected const GAME_LOOKUP_COLUMN = 'espn_event_id';
}
