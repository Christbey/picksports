<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\GeneratePrediction;
use App\Console\Commands\Sports\AbstractCollegeGeneratePredictionsCommand;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;

class GeneratePredictionsCommand extends AbstractCollegeGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'cfb:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate CFB game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const PREDICTION_MODEL_CLASS = Prediction::class;

    protected const USES_EASTERN_DATE_WINDOW = true;

    protected function homeOffColumn(): string
    {
        return 'home_off_rating';
    }

    protected function homeDefColumn(): string
    {
        return 'home_def_rating';
    }

    protected function awayOffColumn(): string
    {
        return 'away_off_rating';
    }

    protected function awayDefColumn(): string
    {
        return 'away_def_rating';
    }
}
