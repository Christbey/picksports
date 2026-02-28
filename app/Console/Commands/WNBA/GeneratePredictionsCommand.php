<?php

namespace App\Console\Commands\WNBA;

use App\Console\Commands\Sports\AbstractGeneratePredictionsCommand;

class GeneratePredictionsCommand extends AbstractGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'wnba:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate WNBA game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = \App\Actions\WNBA\GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = \App\Models\WNBA\Game::class;

    protected const PREDICTION_MODEL_CLASS = \App\Models\WNBA\Prediction::class;

    protected const TEAM_NAME_FIELDS = ['city', 'name'];
}
