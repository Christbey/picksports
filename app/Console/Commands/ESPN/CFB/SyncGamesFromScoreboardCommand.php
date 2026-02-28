<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSeasonalSyncGamesFromScoreboardCommand;
use App\Jobs\ESPN\CFB\FetchGamesFromScoreboard;

class SyncGamesFromScoreboardCommand extends AbstractSeasonalSyncGamesFromScoreboardCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-games-scoreboard';
    protected const SPORT_CODE = 'CFB';
    protected const SEASON_START_MONTH = 9;
    protected const SEASON_END_MONTH = 1;
    protected const SCOREBOARD_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
