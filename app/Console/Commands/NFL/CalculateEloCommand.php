<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'nfl:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate NFL team Elo ratings based on completed games';

    protected const SPORT_NAME = 'NFL';

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;
}
