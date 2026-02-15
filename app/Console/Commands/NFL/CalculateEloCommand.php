<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected $signature = 'nfl:calculate-elo
                            {--season= : Calculate Elo for a specific season}
                            {--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}
                            {--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}
                            {--reset : Reset all Elo ratings to default (1500) before calculating}';

    protected $description = 'Calculate NFL team Elo ratings based on completed games';

    protected function getSportName(): string
    {
        return 'NFL';
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
