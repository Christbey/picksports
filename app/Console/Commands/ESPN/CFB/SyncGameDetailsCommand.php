<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\CFB\FetchGameDetails;
use App\Models\CFB\Game;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-game-details';
    protected const SPORT_CODE = 'CFB';
    protected const GAME_MODEL_CLASS = Game::class;
    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;
}
