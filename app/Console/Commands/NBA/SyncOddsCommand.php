<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\NBA\Concerns\ResolvesNbaOddsSportKey;
use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    use ResolvesNbaOddsSportKey;

    protected const COMMAND_NAME = 'nba:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for NBA games';

    protected const SYNC_ACTION_CLASS = \App\Actions\OddsApi\NBA\SyncOddsForGames::class;

    protected function defaultOddsSportKey(): ?string
    {
        return $this->resolveAutomaticNbaOddsSportKey();
    }
}
