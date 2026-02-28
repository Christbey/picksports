<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncGamesCommand;
use App\Jobs\ESPN\NFL\FetchGames;

class SyncGamesCommand extends AbstractSyncGamesCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-games';
    protected const SPORT_CODE = 'NFL';
    protected const GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
