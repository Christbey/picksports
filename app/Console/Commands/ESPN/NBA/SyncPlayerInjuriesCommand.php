<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Console\Commands\ESPN\AbstractSyncInjuriesCommand;
use App\Jobs\ESPN\NBA\FetchPlayerInjuries;

class SyncPlayerInjuriesCommand extends AbstractSyncInjuriesCommand
{
    protected const COMMAND_NAME = 'espn:sync-nba-injuries';
    protected const SPORT_CODE = 'NBA';
    protected const INJURIES_SYNC_JOB_CLASS = FetchPlayerInjuries::class;
}
