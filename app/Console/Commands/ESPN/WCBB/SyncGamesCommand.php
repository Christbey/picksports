<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Console\Commands\ESPN\AbstractSyncGamesCommand;
use App\Jobs\ESPN\WCBB\FetchGames;

class SyncGamesCommand extends AbstractSyncGamesCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-games';
    protected const SPORT_CODE = 'WCBB';
    protected const GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
