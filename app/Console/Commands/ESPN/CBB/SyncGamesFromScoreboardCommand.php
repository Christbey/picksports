<?php

namespace App\Console\Commands\ESPN\CBB;

use App\Console\Commands\ESPN\AbstractSeasonalSyncGamesFromScoreboardCommand;
use App\Jobs\ESPN\CBB\FetchGamesFromScoreboard;

class SyncGamesFromScoreboardCommand extends AbstractSeasonalSyncGamesFromScoreboardCommand
{
    protected const COMMAND_NAME = 'espn:sync-cbb-games-scoreboard';
    protected const SPORT_CODE = 'CBB';
    protected const SEASON_START_MONTH = 11;
    protected const SEASON_END_MONTH = 4;
    protected const SCOREBOARD_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;
}
