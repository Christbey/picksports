<?php

namespace App\Console\Commands\WNBA;

use App\Actions\OddsApi\WNBA\SyncHistoricalOddsForGames;
use App\Console\Commands\Sports\AbstractSyncHistoricalOddsCommand;

class SyncHistoricalOddsCommand extends AbstractSyncHistoricalOddsCommand
{
    protected const COMMAND_NAME = 'wnba:sync-historical-odds';

    protected const COMMAND_DESCRIPTION = 'Sync historical WNBA odds snapshots from The Odds API';

    protected const SYNC_ACTION_CLASS = SyncHistoricalOddsForGames::class;

    protected function defaultOddsSportKey(): ?string
    {
        return 'basketball_wnba';
    }
}
