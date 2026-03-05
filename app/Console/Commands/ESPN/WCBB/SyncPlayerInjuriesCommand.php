<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Console\Commands\ESPN\AbstractSyncInjuriesCommand;
use App\Jobs\ESPN\WCBB\FetchPlayerInjuries;

class SyncPlayerInjuriesCommand extends AbstractSyncInjuriesCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-injuries';
    protected const SPORT_CODE = 'WCBB';
    protected const INJURIES_SYNC_JOB_CLASS = FetchPlayerInjuries::class;
}
