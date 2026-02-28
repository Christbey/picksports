<?php

namespace App\Console\Commands\CBB;

use App\Console\Commands\Sports\AbstractGradePredictionsCommand;

class GradePredictionsCommand extends AbstractGradePredictionsCommand
{
    protected const COMMAND_NAME = 'cbb:grade-predictions';

    protected const COMMAND_DESCRIPTION = 'Grade CBB predictions against actual game outcomes and display accuracy metrics';

    protected const GRADE_ACTION_CLASS = \App\Actions\CBB\GradePredictions::class;
}
