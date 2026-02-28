<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncPlaysCommand;
use App\Jobs\ESPN\CFB\FetchPlays;

class SyncPlaysCommand extends AbstractSyncPlaysCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-plays';
    protected const SPORT_CODE = 'CFB';
    protected const PLAYS_SYNC_JOB_CLASS = FetchPlays::class;
}
