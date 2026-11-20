<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Models\CBB\Game;
use App\Services\ESPN\CBB\EspnService;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService, SyncPlayerStats $syncPlayerStats, SyncTeamStats $syncTeamStats, SyncPlays $syncPlays)
    {
        parent::__construct($espnService, $syncPlayerStats, $syncTeamStats, $syncPlays);
    }
}
