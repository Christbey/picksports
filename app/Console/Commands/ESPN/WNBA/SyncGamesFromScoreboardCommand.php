<?php

namespace App\Console\Commands\ESPN\WNBA;

use App\Console\Commands\ESPN\AbstractSeasonalSyncGamesFromScoreboardCommand;
use App\Jobs\ESPN\WNBA\FetchGamesFromScoreboard;

class SyncGamesFromScoreboardCommand extends AbstractSeasonalSyncGamesFromScoreboardCommand
{
    protected const COMMAND_NAME = 'espn:sync-wnba-games-scoreboard';
    protected const SPORT_CODE = 'WNBA';
    protected const SEASON_START_MONTH = 5;
    protected const SEASON_END_MONTH = 9;
    protected const SCOREBOARD_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
