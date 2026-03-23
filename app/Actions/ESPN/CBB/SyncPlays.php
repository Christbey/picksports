<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\BasketballPlayData;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = BasketballPlayData::class;

    protected const USE_GAME_PLAYS_PAYLOAD = true;
}
