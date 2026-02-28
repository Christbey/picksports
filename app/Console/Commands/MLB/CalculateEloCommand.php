<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'mlb:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate MLB team and pitcher Elo ratings based on completed games';

    protected const SPORT_NAME = 'MLB';

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;

    protected function getAnalyticsSeasonTypes(): ?array
    {
        return config('mlb.season.analytics_types');
    }
}
