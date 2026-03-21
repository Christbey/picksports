<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncInjuriesCommand;
use App\Jobs\ESPN\CFB\FetchPlayerInjuries;

class SyncPlayerInjuriesCommand extends AbstractSyncInjuriesCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-injuries';

    protected const SPORT_CODE = 'CFB';

    protected const INJURIES_SYNC_JOB_CLASS = FetchPlayerInjuries::class;
}
