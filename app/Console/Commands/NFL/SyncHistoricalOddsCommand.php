<?php

namespace App\Console\Commands\NFL;

use App\Actions\OddsApi\NFL\SyncHistoricalOddsForGames;
use App\Console\Commands\NFL\Concerns\ResolvesNflOddsSportKey;
use App\Console\Commands\Sports\AbstractSyncHistoricalOddsCommand;

class SyncHistoricalOddsCommand extends AbstractSyncHistoricalOddsCommand
{
    use ResolvesNflOddsSportKey;

    protected const COMMAND_NAME = 'nfl:sync-historical-odds';

    protected const COMMAND_DESCRIPTION = 'Sync historical NFL odds snapshots from The Odds API';

    protected const SYNC_ACTION_CLASS = SyncHistoricalOddsForGames::class;

    protected function defaultOddsSportKey(): ?string
    {
        return $this->resolveAutomaticNflOddsSportKey();
    }
}
