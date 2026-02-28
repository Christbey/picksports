<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\Sports\AbstractSyncPlayerPropsCommand;

class SyncPlayerPropsCommand extends AbstractSyncPlayerPropsCommand
{
    protected const COMMAND_NAME = 'nba:sync-player-props';

    protected const COMMAND_DESCRIPTION = 'Sync NBA player props from The Odds API';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\NBA\SyncPlayerPropsForGames::class;

    protected const SPORT_LABEL = 'NBA';
}
