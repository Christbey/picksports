<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncGamesCommand;
use App\Jobs\ESPN\CBB\FetchGames;

class SyncGamesCommand extends AbstractSyncGamesCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-games';
    protected const SPORT_CODE = 'CBB';
    protected const GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
