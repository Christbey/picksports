<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Console\Commands\ESPN\AbstractSyncPlaysCommand;
use App\Jobs\ESPN\WCBB\FetchPlays;

class SyncPlaysCommand extends AbstractSyncPlaysCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-plays';
    protected const SPORT_CODE = 'WCBB';
    protected const PLAYS_SYNC_JOB_CLASS = FetchPlays::class;
}
