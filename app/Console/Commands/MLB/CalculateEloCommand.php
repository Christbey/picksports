<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Models\MLB\Team;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected $signature = 'mlb:calculate-elo
                            {--season= : Calculate Elo for a specific season}
                            {--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}
                            {--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}
                            {--reset : Reset all Elo ratings to default (1500) before calculating}';

    protected $description = 'Calculate MLB team and pitcher Elo ratings based on completed games';

    protected function getSportName(): string
    {
        return 'MLB';
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

    protected function getAnalyticsSeasonTypes(): ?array
    {
        return config('mlb.season.analytics_types');
    }
}
