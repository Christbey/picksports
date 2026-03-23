<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\GeneratePrediction;
use App\Console\Commands\Sports\AbstractCollegeGeneratePredictionsCommand;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;

class GeneratePredictionsCommand extends AbstractCollegeGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'wcbb:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate WCBB game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const PREDICTION_MODEL_CLASS = Prediction::class;

    protected const USES_EASTERN_DATE_WINDOW = true;
}
