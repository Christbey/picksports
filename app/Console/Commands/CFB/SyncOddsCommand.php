<?php

namespace App\Console\Commands\CFB;

use App\Actions\OddsApi\CFB\SyncOddsForGames;
use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    protected const COMMAND_NAME = 'cfb:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for CFB games';

    protected const SYNC_ACTION_CLASS = SyncOddsForGames::class;

    protected const REPORT_MATCH_COVERAGE = true;

    protected const MIN_MATCH_COVERAGE_PERCENT = 80.0;
}
