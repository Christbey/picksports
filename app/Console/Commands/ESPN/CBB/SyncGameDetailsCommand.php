<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\CBB\FetchGameDetails;
use App\Models\CBB\Game;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-game-details';
    protected const SPORT_CODE = 'CBB';
    protected const GAME_MODEL_CLASS = Game::class;
    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;
}
