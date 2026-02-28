<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\WCBB\FetchGameDetails;
use App\Models\WCBB\Game;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-game-details';
    protected const SPORT_CODE = 'WCBB';
    protected const GAME_MODEL_CLASS = Game::class;
    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;
}
