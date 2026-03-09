<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\AbstractSyncOddsCommand;
use App\Console\Commands\MLB\Concerns\ResolvesMlbOddsSportKey;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    use ResolvesMlbOddsSportKey;

    protected const COMMAND_NAME = 'mlb:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for MLB games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\MLB\SyncOddsForGames::class;

    protected function defaultOddsSportKey(): ?string
    {
        return $this->resolveAutomaticMlbOddsSportKey();
    }
}
