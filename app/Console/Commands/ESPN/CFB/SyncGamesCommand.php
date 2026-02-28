<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncGamesCommand;
use App\Jobs\ESPN\CFB\FetchGames;

class SyncGamesCommand extends AbstractSyncGamesCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-games';
    protected const SPORT_CODE = 'CFB';
    protected const GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
