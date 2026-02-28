<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\AbstractSyncPlayerPropsCommand;

class SyncPlayerPropsCommand extends AbstractSyncPlayerPropsCommand
{
    protected const COMMAND_NAME = 'mlb:sync-player-props';

    protected const COMMAND_DESCRIPTION = 'Sync MLB player props from The Odds API';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\MLB\SyncPlayerPropsForGames::class;

    protected const SPORT_LABEL = 'MLB';
}
