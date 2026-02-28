<?php

namespace App\Console\Commands\WCBB;

use App\Console\Commands\Sports\AbstractCollegeGeneratePredictionsCommand;

class GeneratePredictionsCommand extends AbstractCollegeGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'wcbb:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate WCBB game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = \App\Actions\WCBB\GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = \App\Models\WCBB\Game::class;

    protected const PREDICTION_MODEL_CLASS = \App\Models\WCBB\Prediction::class;
}
