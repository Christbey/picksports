<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncPlayersCommand;
use App\Jobs\ESPN\NFL\FetchPlayers;

class SyncPlayersCommand extends AbstractSyncPlayersCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-players';
    protected const SPORT_CODE = 'NFL';
    protected const PLAYERS_SYNC_JOB_CLASS = FetchPlayers::class;
}
