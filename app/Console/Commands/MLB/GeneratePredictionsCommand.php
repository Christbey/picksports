<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\GeneratePrediction;
use Illuminate\Console\Command;

class GeneratePredictionsCommand extends Command
{
    protected $signature = 'mlb:generate-predictions
                            {--season= : Generate predictions for a specific season (required)}';

    protected $description = 'Generate MLB game predictions for scheduled games';

    public function handle(): int
    {
        $season = $this->option('season');

        if (! $season) {
            $this->error('The --season option is required.');

            return Command::FAILURE;
        }

        $this->info("Generating predictions for scheduled games in the {$season} season...");

        $action = new GeneratePrediction;
        $generated = $action->executeForAllScheduledGames((int) $season);

        $this->info("Predictions generated for {$generated} scheduled games.");

        return Command::SUCCESS;
    }
}
