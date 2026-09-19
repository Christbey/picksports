<?php

namespace App\Console\Commands\ESPN\CFB;

use App\Console\Commands\ESPN\AbstractSeasonalSyncGamesFromScoreboardCommand;
use App\Jobs\ESPN\CFB\FetchGamesFromScoreboard;
use Carbon\Carbon;

class SyncGamesFromScoreboardCommand extends AbstractSeasonalSyncGamesFromScoreboardCommand
{
    protected const COMMAND_NAME = 'espn:sync-cfb-games-scoreboard';

    protected const SPORT_CODE = 'CFB';

    protected const SEASON_START_MONTH = 8;

    protected const SEASON_END_MONTH = 1;

    protected const SCOREBOARD_SYNC_JOB_CLASS = FetchGamesFromScoreboard::class;

    /** @return array{0: Carbon, 1: Carbon} */
    protected function seasonDateRange(int $season): array
    {
        return [
            Carbon::create($season, static::SEASON_START_MONTH, 1)->startOfMonth(),
            Carbon::create($season + 1, static::SEASON_END_MONTH, 1)->endOfMonth(),
        ];
    }
}
