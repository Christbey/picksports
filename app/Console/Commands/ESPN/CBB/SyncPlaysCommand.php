<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncPlaysCommand;
use App\Jobs\ESPN\CBB\FetchPlays;

class SyncPlaysCommand extends AbstractSyncPlaysCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-plays';
    protected const SPORT_CODE = 'CBB';
    protected const PLAYS_SYNC_JOB_CLASS = FetchPlays::class;
}
