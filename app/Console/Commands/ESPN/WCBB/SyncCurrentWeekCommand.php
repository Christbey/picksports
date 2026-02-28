<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Console\Commands\ESPN\AbstractSyncCurrentRangeCommand;
use App\Jobs\ESPN\WCBB\FetchGamesFromScoreboard;
use App\Jobs\ESPN\WCBB\FetchTeams;

class SyncCurrentWeekCommand extends AbstractSyncCurrentRangeCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-current';
    protected const SPORT_CODE = 'WCBB';
    protected const TEAM_SYNC_JOB_CLASS = FetchTeams::class;
    protected const CURRENT_DATE_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
