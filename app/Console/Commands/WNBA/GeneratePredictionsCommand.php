<?php

namespace App\Console\Commands\WNBA;

use App\Actions\WNBA\GeneratePrediction;
use App\Console\Commands\Sports\AbstractGeneratePredictionsCommand;
use App\Models\WNBA\Game;
use App\Models\WNBA\Prediction;

class GeneratePredictionsCommand extends AbstractGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'wnba:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate WNBA game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const PREDICTION_MODEL_CLASS = Prediction::class;

    protected const TEAM_NAME_FIELDS = ['location', 'name'];
}
