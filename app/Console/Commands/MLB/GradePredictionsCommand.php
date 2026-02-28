<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\AbstractGradePredictionsCommand;

class GradePredictionsCommand extends AbstractGradePredictionsCommand
{
    protected const COMMAND_NAME = 'mlb:grade-predictions';

    protected const COMMAND_DESCRIPTION = 'Grade MLB predictions against actual game outcomes and display accuracy metrics';

    protected const GRADE_ACTION_CLASS = \App\Actions\MLB\GradePredictions::class;
}
