<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\CBB\EloRating;
use App\Models\CBB\Game;
use App\Models\CBB\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected $signature = 'cbb:calculate-elo
                            {--season= : Calculate Elo for a specific season}
                            {--week= : Calculate Elo for a specific week}
                            {--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}
                            {--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}
                            {--reset : Reset all Elo ratings to default (1500) before calculating}
                            {--regress : Apply 30% regression toward mean (1500) before calculating}';

    protected $description = 'Calculate CBB team Elo ratings based on completed games';

    protected function getSportName(): string
    {
        return 'CBB';
    }

    protected function getGameModel(): string
    {
        return Game::class;
    }

    protected function getTeamModel(): string
    {
        return Team::class;
    }

    protected function getEloRatingModel(): string
    {
        return EloRating::class;
    }

    protected function getCalculateEloAction(): string
    {
        return CalculateElo::class;
    }
}
