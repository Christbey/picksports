<?php

namespace App\Console\Commands\NFL;

use App\Console\Commands\Sports\AbstractGradePlayerPropsCommand;

class GradePlayerPropsCommand extends AbstractGradePlayerPropsCommand
{
    protected const COMMAND_NAME = 'nfl:grade-player-props';

    protected const COMMAND_DESCRIPTION = 'Grade NFL player props against actual player statistics';

    protected const SPORT_KEY = 'americanfootball_nfl';

    protected const SPORT_LABEL = 'NFL';
}
