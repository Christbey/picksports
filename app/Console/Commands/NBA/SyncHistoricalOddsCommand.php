<?php

namespace App\Console\Commands\NBA;

use App\Actions\OddsApi\NBA\SyncHistoricalOddsForGames;
use App\Console\Commands\Sports\AbstractSyncHistoricalOddsCommand;

class SyncHistoricalOddsCommand extends AbstractSyncHistoricalOddsCommand
{
    protected const COMMAND_NAME = 'nba:sync-historical-odds';

    protected const COMMAND_DESCRIPTION = 'Sync historical NBA odds snapshots from The Odds API';

    protected const SYNC_ACTION_CLASS = SyncHistoricalOddsForGames::class;

    protected function defaultOddsSportKey(): ?string
    {
        return 'basketball_nba';
    }
}
