<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\GradePredictions;
use App\Console\Commands\Sports\AbstractGradePredictionsCommand;

class GradePredictionsCommand extends AbstractGradePredictionsCommand
{
    protected const COMMAND_NAME = 'wcbb:grade-predictions';

    protected const COMMAND_DESCRIPTION = 'Grade WCBB predictions against actual game outcomes and display accuracy metrics';

    protected const GRADE_ACTION_CLASS = GradePredictions::class;
}
