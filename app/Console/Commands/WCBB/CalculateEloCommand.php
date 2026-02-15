<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\WCBB\EloRating;
use App\Models\WCBB\Game;
use App\Models\WCBB\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected $signature = 'wcbb:calculate-elo
                            {--season= : Calculate Elo for a specific season}
                            {--week= : Calculate Elo for a specific week}
                            {--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}
                            {--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}
                            {--reset : Reset all Elo ratings to default (1500) before calculating}';

    protected $description = 'Calculate WCBB team Elo ratings based on completed games';

    protected function getSportName(): string
    {
        return 'WCBB';
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
