<?php

namespace App\Console\Commands\CBB;

use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    protected const COMMAND_NAME = 'cbb:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for CBB games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\CBB\SyncOddsForGames::class;
}
