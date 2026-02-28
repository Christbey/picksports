<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncPlayersCommand;
use App\Jobs\ESPN\CBB\FetchPlayers;

class SyncPlayersCommand extends AbstractSyncPlayersCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-players';
    protected const SPORT_CODE = 'CBB';
    protected const PLAYERS_SYNC_JOB_CLASS = FetchPlayers::class;
}
