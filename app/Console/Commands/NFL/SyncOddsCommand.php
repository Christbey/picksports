<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    protected const COMMAND_NAME = 'nfl:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for NFL games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\NFL\SyncOddsForGames::class;
}
