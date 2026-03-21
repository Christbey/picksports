<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Console\Commands\ESPN\AbstractSyncInjuriesCommand;
use App\Jobs\ESPN\WNBA\FetchPlayerInjuries;

class SyncPlayerInjuriesCommand extends AbstractSyncInjuriesCommand
{
    protected const COMMAND_NAME = 'espn:sync-wnba-injuries';

    protected const SPORT_CODE = 'WNBA';

    protected const INJURIES_SYNC_JOB_CLASS = FetchPlayerInjuries::class;
}
