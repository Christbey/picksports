<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use Illuminate\Console\Command;

abstract class AbstractSyncOddsCommand extends Command
{
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const DAYS_OPTION_DESCRIPTION = 'Number of days ahead to sync odds for (default: 7)';

    protected const SYNC_ACTION_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $days = $this->option('days') ?? 7;

        $this->info("Syncing odds for upcoming games (next {$days} days)...");

        $updated = app($this->syncActionClass())->execute($days);

        if ($updated === 0) {
            $this->warn('No games were updated with odds data.');

            return self::SUCCESS;
        }

        $this->info("Successfully updated odds for {$updated} games.");

        return self::SUCCESS;
    }

    /**
     * @return class-string
     */
    protected function syncActionClass(): string
    {
        return $this->requiredString(static::SYNC_ACTION_CLASS, 'SYNC_ACTION_CLASS must be defined on sync-odds command.');
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--days= : %s}",
            $this->commandName(),
            static::DAYS_OPTION_DESCRIPTION
        );
    }

    protected function commandName(): string
    {
        return $this->requiredString(static::COMMAND_NAME, 'COMMAND_NAME must be defined.');
    }

    protected function commandDescription(): string
    {
        return $this->requiredString(static::COMMAND_DESCRIPTION, 'COMMAND_DESCRIPTION must be defined.');
    }
}
