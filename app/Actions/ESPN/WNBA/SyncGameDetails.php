<?php

namespace App\Actions\ESPN\WNBA;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;
use App\Models\WNBA\Game;
use App\Services\ESPN\WNBA\EspnService;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService, SyncPlayerStats $syncPlayerStats, SyncTeamStats $syncTeamStats, SyncPlays $syncPlays)
    {
        parent::__construct($espnService, $syncPlayerStats, $syncTeamStats, $syncPlays);
    }
}
