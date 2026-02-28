<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Console\Commands\ESPN\AbstractSyncCurrentWeekNumberCommand;
use App\Jobs\ESPN\WNBA\FetchGames;
use App\Jobs\ESPN\WNBA\FetchTeams;

class SyncCurrentWeekCommand extends AbstractSyncCurrentWeekNumberCommand
{
    protected const COMMAND_NAME = 'espn:sync-wnba-current';
    protected const SPORT_CODE = 'WNBA';
    protected const SEASON_START_MONTH = 5;
    protected const SEASON_START_DAY = 8;
    protected const MAX_REGULAR_SEASON_WEEKS = 15;
    protected const TEAM_SYNC_JOB_CLASS = FetchTeams::class;
    protected const WEEK_GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
