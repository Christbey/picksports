<?php

namespace App\Actions\ESPN\WCBB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\BasketballPlayData;
use App\Models\WCBB\Game;
use App\Models\WCBB\Play;
use App\Models\WCBB\Team;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = BasketballPlayData::class;
}
