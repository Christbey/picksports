<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\NFL\FetchGameDetails;
use App\Models\NFL\Game;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-game-details';
    protected const SPORT_CODE = 'NFL';
    protected const REQUIRES_FINAL_STATUS = true;
    protected const GAME_MODEL_CLASS = Game::class;
    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;
}
