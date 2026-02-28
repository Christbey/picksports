<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\NBA\FetchGameDetails;
use App\Models\NBA\Game;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-nba-game-details';
    protected const SPORT_CODE = 'NBA';
    protected const GAME_MODEL_CLASS = Game::class;
    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;
}
