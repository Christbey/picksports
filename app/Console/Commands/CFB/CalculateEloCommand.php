<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'cfb:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate CFB team Elo ratings based on completed games';

    protected const SPORT_NAME = 'CFB';

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;
}
