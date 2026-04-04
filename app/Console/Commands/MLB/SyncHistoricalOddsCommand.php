<?php

namespace App\Console\Commands\MLB;

use App\Actions\OddsApi\MLB\SyncHistoricalOddsForGames;
use App\Console\Commands\Sports\AbstractSyncHistoricalOddsCommand;

class SyncHistoricalOddsCommand extends AbstractSyncHistoricalOddsCommand
{
    protected const COMMAND_NAME = 'mlb:sync-historical-odds';

    protected const COMMAND_DESCRIPTION = 'Sync historical MLB odds snapshots from The Odds API';

    protected const SYNC_ACTION_CLASS = SyncHistoricalOddsForGames::class;

    protected function defaultOddsSportKey(): ?string
    {
        return 'baseball_mlb';
    }
}
