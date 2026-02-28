<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncCurrentWeekNumberCommand;
use App\Jobs\ESPN\NFL\FetchGames;
use App\Jobs\ESPN\NFL\FetchTeams;

class SyncCurrentWeekCommand extends AbstractSyncCurrentWeekNumberCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-current';
    protected const SPORT_CODE = 'NFL';
    protected const SEASON_START_MONTH = 9;
    protected const SEASON_START_DAY = 1;
    protected const MAX_REGULAR_SEASON_WEEKS = 18;
    protected const TEAM_SYNC_JOB_CLASS = FetchTeams::class;
    protected const WEEK_GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
