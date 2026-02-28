<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use Illuminate\Console\Command;

abstract class AbstractSyncPlayerPropsCommand extends Command
{
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const MARKETS_OPTION_DESCRIPTION = 'Specific markets to fetch (defaults to common props)';

    protected const SYNC_ACTION_CLASS = '';

    protected const SPORT_LABEL = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $this->info("Syncing {$this->sportLabel()} player props from The Odds API...");

        $markets = $this->option('markets');
        $markets = empty($markets) ? null : $markets;

        $stored = app($this->syncActionClass())->execute($markets);

        $this->info("Successfully stored {$stored} player props.");

        return self::SUCCESS;
    }

    /**
     * @return class-string
     */
    protected function syncActionClass(): string
    {
        return $this->requiredString(static::SYNC_ACTION_CLASS, 'SYNC_ACTION_CLASS must be defined on sync-player-props command.');
    }

    protected function sportLabel(): string
    {
        return $this->requiredString(static::SPORT_LABEL, 'SPORT_LABEL must be defined on sync-player-props command.');
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--markets=* : %s}",
            $this->commandName(),
            static::MARKETS_OPTION_DESCRIPTION
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
