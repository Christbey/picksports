<?php

namespace App\Console\Commands\ESPN\WCBB;

use App\Console\Commands\ESPN\AbstractSeasonalSyncGamesFromScoreboardCommand;
use App\Jobs\ESPN\WCBB\FetchGamesFromScoreboard;

class SyncGamesFromScoreboardCommand extends AbstractSeasonalSyncGamesFromScoreboardCommand
{
    protected const COMMAND_NAME = 'espn:sync-wcbb-games-scoreboard';
    protected const SPORT_CODE = 'WCBB';
    protected const SEASON_START_MONTH = 11;
    protected const SEASON_END_MONTH = 4;
    protected const SCOREBOARD_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
