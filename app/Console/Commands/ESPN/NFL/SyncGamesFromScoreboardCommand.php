<?php

namespace App\Console\Commands\ESPN\NFL;

use App\Console\Commands\ESPN\AbstractSyncGamesFromScoreboardCommand;
use App\Jobs\ESPN\NFL\FetchGamesFromScoreboard;

class SyncGamesFromScoreboardCommand extends AbstractSyncGamesFromScoreboardCommand
{
    protected const COMMAND_NAME = 'espn:sync-nfl-games-scoreboard';
    protected const SPORT_CODE = 'NFL';
    protected const SCOREBOARD_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
