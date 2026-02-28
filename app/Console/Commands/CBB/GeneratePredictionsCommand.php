<?php

namespace App\Console\Commands\CBB;

use App\Console\Commands\Sports\AbstractCollegeGeneratePredictionsCommand;

class GeneratePredictionsCommand extends AbstractCollegeGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'cbb:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate CBB game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = \App\Actions\CBB\GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;

    protected const PREDICTION_MODEL_CLASS = \App\Models\CBB\Prediction::class;

    protected const USES_EASTERN_DATE_WINDOW = true;
}
