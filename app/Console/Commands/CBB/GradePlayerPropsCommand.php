<?php

namespace App\Console\Commands\CBB;

use App\Console\Commands\Sports\AbstractGradePlayerPropsCommand;

class GradePlayerPropsCommand extends AbstractGradePlayerPropsCommand
{
    protected const COMMAND_NAME = 'cbb:grade-player-props';

    protected const COMMAND_DESCRIPTION = 'Grade CBB player props against actual player statistics';

    protected const SPORT_KEY = 'basketball_ncaab';

    protected const SPORT_LABEL = 'CBB';
}
