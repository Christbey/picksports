<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\GradePredictions;
use App\Console\Commands\Sports\AbstractGradePredictionsCommand;

class GradePredictionsCommand extends AbstractGradePredictionsCommand
{
    protected const COMMAND_NAME = 'nba:grade-predictions';

    protected const COMMAND_DESCRIPTION = 'Grade NBA predictions against actual game outcomes and display accuracy metrics';

    protected const GRADE_ACTION_CLASS = GradePredictions::class;
}
