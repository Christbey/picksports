<?php

namespace App\Console\Commands\WNBA;

use App\Actions\OddsApi\WNBA\SyncPlayerPropsForGames;
use App\Console\Commands\Sports\AbstractSyncPlayerPropsCommand;

class SyncPlayerPropsCommand extends AbstractSyncPlayerPropsCommand
{
    protected const COMMAND_NAME = 'wnba:sync-player-props';

    protected const COMMAND_DESCRIPTION = 'Sync WNBA player props from The Odds API';

    protected const SYNC_ACTION_CLASS = SyncPlayerPropsForGames::class;

    protected const SPORT_LABEL = 'WNBA';
}
