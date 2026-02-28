<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Console\Commands\ESPN\AbstractSyncPlaysCommand;
use App\Jobs\ESPN\WNBA\FetchPlays;

class SyncPlaysCommand extends AbstractSyncPlaysCommand
{
    protected const COMMAND_NAME = 'espn:sync-wnba-plays';
    protected const SPORT_CODE = 'WNBA';
    protected const PLAYS_SYNC_JOB_CLASS = FetchPlays::class;
}
