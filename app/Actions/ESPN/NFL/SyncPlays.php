<?php

namespace App\Actions\ESPN\NFL;

use App\Actions\ESPN\AbstractSyncPlays;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = \App\Models\NFL\Game::class;

    protected const PLAY_MODEL_CLASS = \App\Models\NFL\Play::class;

    protected const TEAM_MODEL_CLASS = \App\Models\NFL\Team::class;

    protected const PLAY_DTO_CLASS = \App\DataTransferObjects\ESPN\FootballPlayData::class;

    protected const USE_EVENT_ID_AS_COMPETITION_ID = true;

    protected const SKIP_EMPTY_PLAY_ID = true;
}
