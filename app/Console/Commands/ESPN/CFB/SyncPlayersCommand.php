<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncPlayersCommand;
use App\Jobs\ESPN\CFB\FetchPlayers;

class SyncPlayersCommand extends AbstractSyncPlayersCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-players';
    protected const SPORT_CODE = 'CFB';
    protected const PLAYERS_SYNC_JOB_CLASS = FetchPlayers::class;
}
