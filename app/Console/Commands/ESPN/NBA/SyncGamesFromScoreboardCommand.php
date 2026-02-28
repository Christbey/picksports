<?php

namespace App\Console\Commands\ESPN\NBA;

use App\Console\Commands\ESPN\AbstractSeasonalSyncGamesFromScoreboardCommand;
use App\Jobs\ESPN\NBA\FetchGamesFromScoreboard;

class SyncGamesFromScoreboardCommand extends AbstractSeasonalSyncGamesFromScoreboardCommand
{
    protected const COMMAND_NAME = 'espn:sync-nba-games-scoreboard';
    protected const SPORT_CODE = 'NBA';
    protected const SEASON_START_MONTH = 10;
    protected const SEASON_END_MONTH = 6;
    protected const SCOREBOARD_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
