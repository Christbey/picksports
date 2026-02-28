<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\Sports\AbstractGeneratePredictionsCommand;

class GeneratePredictionsCommand extends AbstractGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'nba:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate NBA game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = \App\Actions\NBA\GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = \App\Models\NBA\Game::class;

    protected const PREDICTION_MODEL_CLASS = \App\Models\NBA\Prediction::class;

    protected const TEAM_NAME_FIELDS = ['school', 'mascot'];
}
