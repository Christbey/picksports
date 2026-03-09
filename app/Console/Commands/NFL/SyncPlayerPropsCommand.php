<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\NFL\Concerns\ResolvesNflOddsSportKey;
use App\Console\Commands\Sports\AbstractSyncPlayerPropsCommand;

class SyncPlayerPropsCommand extends AbstractSyncPlayerPropsCommand
{
    use ResolvesNflOddsSportKey;

    protected const COMMAND_NAME = 'nfl:sync-player-props';

    protected const COMMAND_DESCRIPTION = 'Sync NFL player props from The Odds API';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\NFL\SyncPlayerPropsForGames::class;

    protected const SPORT_LABEL = 'NFL';

    protected function defaultOddsSportKey(): ?string
    {
        return $this->resolveAutomaticNflOddsSportKey();
    }
}
