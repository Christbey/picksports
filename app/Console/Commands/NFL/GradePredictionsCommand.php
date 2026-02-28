<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\AbstractGradePredictionsCommand;

class GradePredictionsCommand extends AbstractGradePredictionsCommand
{
    protected const COMMAND_NAME = 'nfl:grade-predictions';

    protected const COMMAND_DESCRIPTION = 'Grade NFL predictions against actual game outcomes and display accuracy metrics';

    protected const GRADE_ACTION_CLASS = \App\Actions\NFL\GradePredictions::class;
}
