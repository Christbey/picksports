<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Console\Commands\ESPN\AbstractSyncCurrentRangeCommand;
use App\Jobs\ESPN\NBA\FetchGamesFromScoreboard;
use App\Jobs\ESPN\NBA\FetchTeams;

class SyncCurrentWeekCommand extends AbstractSyncCurrentRangeCommand
{
    protected const COMMAND_NAME = 'espn:sync-nba-current';
    protected const SPORT_CODE = 'NBA';
    protected const TEAM_SYNC_JOB_CLASS = FetchTeams::class;
    protected const CURRENT_DATE_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
