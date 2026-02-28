<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Console\Commands\ESPN\AbstractSyncPlayersCommand;
use App\Jobs\ESPN\WNBA\FetchPlayers;

class SyncPlayersCommand extends AbstractSyncPlayersCommand
{
    protected const COMMAND_NAME = 'espn:sync-wnba-players';
    protected const SPORT_CODE = 'WNBA';
    protected const PLAYERS_SYNC_JOB_CLASS = FetchPlayers::class;
}
