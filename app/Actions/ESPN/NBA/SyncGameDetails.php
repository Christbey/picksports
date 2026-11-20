<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractStandardSyncGameDetails;
use App\Models\NBA\Game;
use App\Services\ESPN\NBA\EspnService;

class SyncGameDetails extends AbstractStandardSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService, SyncPlayerStats $syncPlayerStats, SyncTeamStats $syncTeamStats, SyncPlays $syncPlays)
    {
        parent::__construct($espnService, $syncPlayerStats, $syncTeamStats, $syncPlays);
    }
}
