<?php

namespace App\Console\Commands\MLB;

use App\Actions\OddsApi\MLB\SyncOddsForGames;
use App\Console\Commands\MLB\Concerns\ResolvesMlbOddsSportKey;
use App\Console\Commands\Sports\AbstractSyncOddsCommand;

class SyncOddsCommand extends AbstractSyncOddsCommand
{
    use ResolvesMlbOddsSportKey;

    protected const COMMAND_NAME = 'mlb:sync-odds';

    protected const COMMAND_DESCRIPTION = 'Sync betting odds from The Odds API for MLB games';

    protected const SYNC_ACTION_CLASS = SyncOddsForGames::class;

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? 7);
        $explicitOddsSport = $this->option('odds-sport');
        $explicitOddsSport = is_string($explicitOddsSport) && $explicitOddsSport !== '' ? $explicitOddsSport : null;

        if ($explicitOddsSport !== null) {
            return parent::handle();
        }

        $oddsSportKeys = $this->resolveAutomaticMlbOddsSportKeys($days);
        $this->info('Syncing odds for upcoming games (next '.$days.' days) using sport key(s) ['.implode(', ', $oddsSportKeys).']...');

        $updated = 0;

        foreach ($oddsSportKeys as $oddsSportKey) {
            $updated += app($this->syncActionClass())->execute($days, $oddsSportKey);
        }

        if ($updated === 0) {
            $this->warn('No games were updated with odds data.');

            return self::SUCCESS;
        }

        $this->info("Successfully updated odds for {$updated} games.");

        return self::SUCCESS;
    }

    protected function defaultOddsSportKey(): ?string
    {
        return $this->resolveAutomaticMlbOddsSportKey();
    }
}
