<?php

namespace App\Console\Commands\WCBB;

use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    protected const COMMAND_NAME = 'wcbb:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for WCBB games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\WCBB\SyncOddsForGames::class;
}
