<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\WCBB\EloRating;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'wcbb:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate WCBB team Elo ratings based on completed games';

    protected const SPORT_NAME = 'WCBB';

    protected const EXTRA_SIGNATURE_OPTIONS = [
        '{--week= : Calculate Elo for a specific week}',
        '{--regress : Apply 30% regression toward mean (1500) before calculating}',
    ];

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;
}
