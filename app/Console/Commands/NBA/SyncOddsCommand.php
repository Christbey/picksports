<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    protected const COMMAND_NAME = 'nba:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for NBA games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\NBA\SyncOddsForGames::class;
}
