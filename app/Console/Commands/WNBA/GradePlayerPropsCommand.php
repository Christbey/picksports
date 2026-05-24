<?php

namespace App\Console\Commands\WNBA;

use App\Console\Commands\Sports\AbstractGradePlayerPropsCommand;

class GradePlayerPropsCommand extends AbstractGradePlayerPropsCommand
{
    protected const COMMAND_NAME = 'wnba:grade-player-props';

    protected const COMMAND_DESCRIPTION = 'Grade WNBA player props against actual player statistics';

    protected const SPORT_KEY = 'basketball_wnba';

    protected const SPORT_LABEL = 'WNBA';
}
