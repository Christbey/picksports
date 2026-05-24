<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Console\Commands\ESPN\AbstractSyncMissingPlayerStatsGameDetailsCommand;
use App\Jobs\ESPN\WNBA\FetchGameDetails;
use App\Models\WNBA\Game;

class SyncGameDetailsCommand extends AbstractSyncMissingPlayerStatsGameDetailsCommand
{
    protected const COMMAND_NAME = 'espn:sync-wnba-game-details';

    protected const SPORT_CODE = 'WNBA';

    protected const GAME_MODEL_CLASS = Game::class;

    protected const GAME_DETAILS_JOB_CLASS = FetchGameDetails::class;
}
