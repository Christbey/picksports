<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = FootballPlayData::class;

    public function __construct(EspnService $espnService)
    {
        parent::__construct($espnService);
    }
}
