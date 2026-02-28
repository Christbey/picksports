<?php

namespace App\Console\Commands\CFB;

use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    protected const COMMAND_NAME = 'cfb:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for CFB games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\CFB\SyncOddsForGames::class;
}
