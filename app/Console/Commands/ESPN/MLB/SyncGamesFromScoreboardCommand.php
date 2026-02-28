<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSeasonalSyncGamesFromScoreboardCommand;
use App\Jobs\ESPN\MLB\FetchGamesFromScoreboard;

class SyncGamesFromScoreboardCommand extends AbstractSeasonalSyncGamesFromScoreboardCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-games-scoreboard';
    protected const SPORT_CODE = 'MLB';
    protected const SEASON_START_MONTH = 4;
    protected const SEASON_END_MONTH = 10;
    protected const SCOREBOARD_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
