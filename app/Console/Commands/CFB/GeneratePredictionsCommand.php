<?php

namespace App\Console\Commands\CFB;

use App\Console\Commands\Sports\AbstractCollegeGeneratePredictionsCommand;

class GeneratePredictionsCommand extends AbstractCollegeGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'cfb:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate CFB game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = \App\Actions\CFB\GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = \App\Models\CFB\Game::class;

    protected const PREDICTION_MODEL_CLASS = \App\Models\CFB\Prediction::class;

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
