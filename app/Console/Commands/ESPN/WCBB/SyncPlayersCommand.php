<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Console\Commands\ESPN\AbstractSyncPlayersCommand;
use App\Jobs\ESPN\WCBB\FetchPlayers;

class SyncPlayersCommand extends AbstractSyncPlayersCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-players';
    protected const SPORT_CODE = 'WCBB';
    protected const PLAYERS_SYNC_JOB_CLASS = FetchPlayers::class;
}
