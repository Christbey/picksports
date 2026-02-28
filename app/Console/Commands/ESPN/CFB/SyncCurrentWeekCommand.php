<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSyncCurrentWeekNumberCommand;
use App\Jobs\ESPN\CFB\FetchGames;
use App\Jobs\ESPN\CFB\FetchTeams;

class SyncCurrentWeekCommand extends AbstractSyncCurrentWeekNumberCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-current';
    protected const SPORT_CODE = 'CFB';
    protected const SEASON_START_MONTH = 8;
    protected const SEASON_START_DAY = 24;
    protected const MAX_REGULAR_SEASON_WEEKS = 15;
    protected const TEAM_SYNC_JOB_CLASS = FetchTeams::class;
    protected const WEEK_GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
