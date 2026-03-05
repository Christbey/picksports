<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncInjuriesCommand;
use App\Jobs\ESPN\NFL\FetchPlayerInjuries;

class SyncPlayerInjuriesCommand extends AbstractSyncInjuriesCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-injuries';
    protected const SPORT_CODE = 'NFL';
    protected const INJURIES_SYNC_JOB_CLASS = FetchPlayerInjuries::class;
}
