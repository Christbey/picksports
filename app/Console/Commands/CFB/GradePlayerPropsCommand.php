<?php

namespace App\Console\Commands\CFB;

use App\Console\Commands\Sports\AbstractGradePlayerPropsCommand;

class GradePlayerPropsCommand extends AbstractGradePlayerPropsCommand
{
    protected const COMMAND_NAME = 'cfb:grade-player-props';

    protected const COMMAND_DESCRIPTION = 'Grade CFB player props against actual player statistics';

    protected const SPORT_KEY = 'americanfootball_ncaaf';

    protected const SPORT_LABEL = 'CFB';
}
