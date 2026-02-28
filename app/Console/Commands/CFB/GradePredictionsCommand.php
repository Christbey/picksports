<?php

namespace App\Console\Commands\CFB;

use App\Console\Commands\Sports\AbstractGradePredictionsCommand;

class GradePredictionsCommand extends AbstractGradePredictionsCommand
{
    protected const COMMAND_NAME = 'cfb:grade-predictions';

    protected const COMMAND_DESCRIPTION = 'Grade CFB predictions against actual game outcomes and display accuracy metrics';

    protected const GRADE_ACTION_CLASS = \App\Actions\CFB\GradePredictions::class;
}
