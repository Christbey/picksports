<?php

namespace App\Console\Commands\NFL;

use App\Actions\OddsApi\NFL\SyncPlayerPropsForGames;
use App\Console\Commands\NFL\Concerns\ResolvesNflOddsSportKey;
use App\Console\Commands\Sports\AbstractSyncPlayerPropsCommand;

class SyncPlayerPropsCommand extends AbstractSyncPlayerPropsCommand
{
    use ResolvesNflOddsSportKey;

    protected const COMMAND_NAME = 'nfl:sync-player-props';

    protected const COMMAND_DESCRIPTION = 'Sync NFL player props from The Odds API';

    protected const SYNC_ACTION_CLASS = SyncPlayerPropsForGames::class;

    protected const SPORT_LABEL = 'NFL';

    public function handle(): int
    {
        $result = parent::handle();
        if ($result !== self::SUCCESS) {
            return $result;
        }

        // Fetch replaces quote rows. Rebuild their deterministic recommendation
        // snapshots immediately; no AI narratives or additional provider calls.
        return $this->call('sports:analyze-player-props', [
            '--sport' => 'nfl',
            '--only-missing' => true,
        ]);
    }

    protected function defaultOddsSportKey(): ?string
    {
        return $this->resolveAutomaticNflOddsSportKey();
    }
}
