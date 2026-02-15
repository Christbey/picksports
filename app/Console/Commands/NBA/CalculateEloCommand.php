<?php

namespace App\Console\Commands\NBA;

use App\Actions\NBA\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\NBA\EloRating;
use App\Models\NBA\Game;
use App\Models\NBA\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected $signature = 'nba:calculate-elo
                            {--season= : Calculate Elo for a specific season}
                            {--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}
                            {--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}
                            {--reset : Reset all Elo ratings to default (1500) before calculating}';

    protected $description = 'Calculate NBA team Elo ratings based on completed games';

    protected function getSportName(): string
    {
        return 'NBA';
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
