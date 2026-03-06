<?php

namespace App\Console\Commands\NBA;

use App\Console\Commands\Sports\AbstractBasketballCalculatePlayEpaCommand;
use App\Models\NBA\Game;
use App\Models\NBA\Play;

class CalculatePlayEpaCommand extends AbstractBasketballCalculatePlayEpaCommand
{
    protected function sportKey(): string
    {
        return 'nba';
    }

    protected $signature = 'nba:calculate-play-epa
        {--season= : Limit to season (e.g. 2025)}
        {--game_id= : Limit to a single nba_games.id}
        {--limit=0 : Limit number of games (0 = all)}
        {--rebuild : Recalculate for all matching plays, including previously scored rows}
        {--dry-run : Preview updates without writing}';

    protected $description = 'Calculate true play-by-play EPA for NBA plays';

    protected function gameModelClass(): string
    {
        return Game::class;
    }

    protected function playModelClass(): string
    {
        return Play::class;
    }
}
