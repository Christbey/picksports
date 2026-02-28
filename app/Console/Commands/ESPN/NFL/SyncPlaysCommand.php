<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncPlaysCommand;
use App\Jobs\ESPN\NFL\FetchPlays;

class SyncPlaysCommand extends AbstractSyncPlaysCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-plays';
    protected const SPORT_CODE = 'NFL';
    protected const PLAYS_SYNC_JOB_CLASS = FetchPlays::class;
}
