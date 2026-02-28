<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\Sports\AbstractGradePlayerPropsCommand;

class GradePlayerPropsCommand extends AbstractGradePlayerPropsCommand
{
    protected const COMMAND_NAME = 'nba:grade-player-props';

    protected const COMMAND_DESCRIPTION = 'Grade NBA player props against actual player statistics';

    protected const SPORT_KEY = 'basketball_nba';

    protected const SPORT_LABEL = 'NBA';
}
