<?php

namespace App\Console\Commands\MLB;

use App\Actions\OddsApi\MLB\SyncPlayerPropsForGames;
use App\Console\Commands\MLB\Concerns\ResolvesMlbOddsSportKey;
use App\Console\Commands\Sports\AbstractSyncPlayerPropsCommand;

class SyncPlayerPropsCommand extends AbstractSyncPlayerPropsCommand
{
    use ResolvesMlbOddsSportKey;

    protected const COMMAND_NAME = 'mlb:sync-player-props';

    protected const COMMAND_DESCRIPTION = 'Sync MLB player props from The Odds API';

    protected const SYNC_ACTION_CLASS = SyncPlayerPropsForGames::class;

    protected const SPORT_LABEL = 'MLB';

    protected function defaultOddsSportKey(): ?string
    {
        return $this->resolveAutomaticMlbOddsSportKey();
    }
}
