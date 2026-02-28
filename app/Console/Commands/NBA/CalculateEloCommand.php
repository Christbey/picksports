<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\NBA\EloRating;
use App\Models\NBA\Game;
use App\Models\NBA\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'nba:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate NBA team Elo ratings based on completed games';

    protected const SPORT_NAME = 'NBA';

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;
}
