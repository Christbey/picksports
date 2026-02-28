<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Console\Commands\ESPN\AbstractSyncGamesCommand;
use App\Jobs\ESPN\WNBA\FetchGames;

class SyncGamesCommand extends AbstractSyncGamesCommand
{
    protected const COMMAND_NAME = 'espn:sync-wnba-games';
    protected const SPORT_CODE = 'WNBA';
    protected const GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
