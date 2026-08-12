<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\GeneratePrediction;
use App\Console\Commands\Sports\AbstractGenerateSeasonScheduledPredictionsCommand;
use App\Models\MLB\Game;
use App\Services\MLB\MlbPeriodFeatureStore;

class GeneratePredictionsCommand extends AbstractGenerateSeasonScheduledPredictionsCommand
{
    protected const COMMAND_NAME = 'mlb:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate MLB game predictions for scheduled games';

    protected const SEASON_OPTION_DESCRIPTION = 'Generate predictions for a specific season (required)';

    protected const GENERATE_ACTION_CLASS = GeneratePrediction::class;

    protected function afterPredictionsGenerated(int $season, int $generated): void
    {
        if ($generated < 1) {
            return;
        }

        $games = Game::query()
            ->where('season', $season)
            ->where('status', config('mlb.statuses.scheduled', 'STATUS_SCHEDULED'))
            ->whereHas('prediction')
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->get();

        $snapshots = app(MlbPeriodFeatureStore::class)->materialize($games);
        $this->info("Materialized {$snapshots->count()} MLB F3/F5 feature snapshots.");
    }
}
