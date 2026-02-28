<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\CBB\EloRating;
use App\Models\CBB\Game;
use App\Models\CBB\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'cbb:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate CBB team Elo ratings based on completed games';

    protected const SPORT_NAME = 'CBB';

    protected const EXTRA_SIGNATURE_OPTIONS = [
        '{--week= : Calculate Elo for a specific week}',
        '{--regress : Apply 30% regression toward mean (1500) before calculating}',
    ];

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;
}
