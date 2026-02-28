<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Console\Commands\ESPN\AbstractSyncGamesCommand;
use App\Jobs\ESPN\NBA\FetchGames;

class SyncGamesCommand extends AbstractSyncGamesCommand
{
    protected const COMMAND_NAME = 'espn:sync-nba-games';
    protected const SPORT_CODE = 'NBA';
    protected const GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
