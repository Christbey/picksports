<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    protected const COMMAND_NAME = 'mlb:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for MLB games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\MLB\SyncOddsForGames::class;
}
