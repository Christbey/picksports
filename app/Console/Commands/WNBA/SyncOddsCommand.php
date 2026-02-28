<?php

namespace App\Console\Commands\WNBA;

use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    protected const COMMAND_NAME = 'wnba:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for WNBA games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\WNBA\SyncOddsForGames::class;
}
