<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\CFB\EloRating;
use App\Models\CFB\Game;
use App\Models\CFB\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected $signature = 'cfb:calculate-elo
                            {--season= : Calculate Elo for a specific season}
                            {--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}
                            {--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}
                            {--reset : Reset all Elo ratings to default before calculating}';

    protected $description = 'Calculate CFB team Elo ratings based on completed games';

    protected function getSportName(): string
    {
        return 'CFB';
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
