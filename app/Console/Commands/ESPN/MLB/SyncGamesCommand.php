<?php

namespace App\Console\Commands\ESPN\MLB;

use App\Console\Commands\ESPN\AbstractSyncGamesCommand;
use App\Jobs\ESPN\MLB\FetchGames;

class SyncGamesCommand extends AbstractSyncGamesCommand
{
    protected const COMMAND_NAME = 'espn:sync-mlb-games';

    protected const SPORT_CODE = 'MLB';

    protected const DEFAULT_SEASON_TYPE = '2';

    protected const SEASON_TYPE_DESCRIPTION = 'The season type (1=spring training, 2=regular, 3=postseason, 4=all-star)';

    protected const GAMES_SYNC_JOB_CLASS = FetchGames::class;
}
