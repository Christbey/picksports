<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\GeneratePrediction;
use App\Console\Commands\Sports\AbstractGeneratePredictionsCommand;
use App\Models\NBA\Game;
use App\Models\NBA\Prediction;
use Illuminate\Database\Eloquent\Builder;

class GeneratePredictionsCommand extends AbstractGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'nba:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate NBA game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const PREDICTION_MODEL_CLASS = Prediction::class;

    protected const TEAM_NAME_FIELDS = ['school', 'mascot'];

    protected function applyFilters(Builder $query): void
    {
        parent::applyFilters($query);

        $query->withoutCompletedPlayoffSeriesPlaceholders();
    }
}
