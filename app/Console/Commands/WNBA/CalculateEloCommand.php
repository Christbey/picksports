<?php

namespace App\Console\Commands\WNBA;

use App\Actions\WNBA\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'wnba:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate WNBA team Elo ratings based on completed games';

    protected const SPORT_NAME = 'WNBA';

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;
}
