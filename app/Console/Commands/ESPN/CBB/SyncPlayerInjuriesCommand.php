<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncInjuriesCommand;
use App\Jobs\ESPN\CBB\FetchPlayerInjuries;

class SyncPlayerInjuriesCommand extends AbstractSyncInjuriesCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-injuries';
    protected const SPORT_CODE = 'CBB';
    protected const INJURIES_SYNC_JOB_CLASS = FetchPlayerInjuries::class;
}
