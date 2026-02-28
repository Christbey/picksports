<?php

namespace App\Console\Commands\WNBA;

use App\Console\Commands\Sports\AbstractGradePredictionsCommand;

class GradePredictionsCommand extends AbstractGradePredictionsCommand
{
    protected const COMMAND_NAME = 'wnba:grade-predictions';

    protected const COMMAND_DESCRIPTION = 'Grade WNBA predictions against actual game outcomes and display accuracy metrics';

    protected const GRADE_ACTION_CLASS = \App\Actions\WNBA\GradePredictions::class;
}
