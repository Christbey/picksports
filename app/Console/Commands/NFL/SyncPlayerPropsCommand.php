<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\AbstractSyncPlayerPropsCommand;

class SyncPlayerPropsCommand extends AbstractSyncPlayerPropsCommand
{
    protected const COMMAND_NAME = 'nfl:sync-player-props';

    protected const COMMAND_DESCRIPTION = 'Sync NFL player props from The Odds API';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\NFL\SyncPlayerPropsForGames::class;

    protected const SPORT_LABEL = 'NFL';
}
