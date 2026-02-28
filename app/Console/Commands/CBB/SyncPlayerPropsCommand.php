<?php

namespace App\Console\Commands\CBB;

use App\Console\Commands\Sports\AbstractSyncPlayerPropsCommand;

class SyncPlayerPropsCommand extends AbstractSyncPlayerPropsCommand
{
    protected const COMMAND_NAME = 'cbb:sync-player-props';

    protected const COMMAND_DESCRIPTION = 'Sync CBB player props from The Odds API';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\CBB\SyncPlayerPropsForGames::class;

    protected const SPORT_LABEL = 'CBB';
}
