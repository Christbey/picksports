<?php

namespace App\Console\Commands\MLB;

use App\Console\Commands\Sports\AbstractGradePlayerPropsCommand;

class GradePlayerPropsCommand extends AbstractGradePlayerPropsCommand
{
    protected const COMMAND_NAME = 'mlb:grade-player-props';

    protected const COMMAND_DESCRIPTION = 'Grade MLB player props against actual player statistics';

    protected const SPORT_KEY = 'baseball_mlb';

    protected const SPORT_LABEL = 'MLB';
}
