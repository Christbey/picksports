<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\BasketballPlayData;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService)
    {
        parent::__construct($espnService);
    }

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = BasketballPlayData::class;
}
