<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSyncGamesCommand;
use App\Jobs\ESPN\MLB\FetchGames;

class SyncGamesCommand extends AbstractSyncGamesCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-games';
    protected const SPORT_CODE = 'MLB';
    protected const DEFAULT_SEASON_TYPE = '1';
    protected const SEASON_TYPE_DESCRIPTION = 'The season type (1=regular, 3=postseason)';
    protected const GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
