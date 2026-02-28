<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSyncPlayersCommand;
use App\Jobs\ESPN\MLB\FetchPlayers;

class SyncPlayersCommand extends AbstractSyncPlayersCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-players';
    protected const SPORT_CODE = 'MLB';
    protected const PLAYERS_SYNC_JOB_CLASS = FetchPlayers::class;
}
