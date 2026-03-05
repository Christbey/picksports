<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSyncInjuriesCommand;
use App\Jobs\ESPN\MLB\FetchPlayerInjuries;

class SyncPlayerInjuriesCommand extends AbstractSyncInjuriesCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-injuries';
    protected const SPORT_CODE = 'MLB';
    protected const INJURIES_SYNC_JOB_CLASS = FetchPlayerInjuries::class;
}
