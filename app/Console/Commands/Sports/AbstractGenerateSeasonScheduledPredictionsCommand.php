<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use App\Support\SportsViewCache;
use Illuminate\Console\Command;

abstract class AbstractGenerateSeasonScheduledPredictionsCommand extends Command
{
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const SEASON_OPTION_DESCRIPTION = 'Generate predictions for a specific season (required)';

    protected const GENERATE_ACTION_CLASS = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $season = $this->option('season');

        if (! $season) {
            $this->error('The --season option is required.');

            return self::FAILURE;
        }

        $this->info("Generating predictions for scheduled games in the {$season} season...");

        $generatePrediction = app($this->generateActionClass());
        $generated = $generatePrediction->executeForAllScheduledGames((int) $season);

        $this->info("Predictions generated for {$generated} scheduled games.");
        if ($generated > 0) {
            app(SportsViewCache::class)->bustSegments([
                SportsViewCache::SEGMENT_DASHBOARD,
                SportsViewCache::SEGMENT_LIVE_SCOREBOARD,
                SportsViewCache::SEGMENT_PREDICTIONS_INDEX,
                SportsViewCache::SEGMENT_PREDICTIONS_BY_GAME,
                SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_DATES,
                SportsViewCache::SEGMENT_PREDICTIONS_AVAILABLE_SEASONS,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * @return class-string
     */
    protected function generateActionClass(): string
    {
        return $this->requiredString(static::GENERATE_ACTION_CLASS, 'GENERATE_ACTION_CLASS must be defined.');
    }

    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--season= : %s}",
            $this->commandName(),
            static::SEASON_OPTION_DESCRIPTION
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
