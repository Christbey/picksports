<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSyncCurrentRangeCommand;
use App\Jobs\ESPN\CBB\FetchGamesFromScoreboard;

class SyncCurrentWeekCommand extends AbstractSyncCurrentRangeCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-current';
    protected const SPORT_CODE = 'CBB';
    protected const CURRENT_DATE_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
