<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = FootballPlayData::class;

    protected const USE_EVENT_ID_AS_COMPETITION_ID = true;

    protected const SKIP_EMPTY_PLAY_ID = true;
}
