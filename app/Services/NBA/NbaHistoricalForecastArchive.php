<?php

namespace App\Services\NBA;

use App\Models\NBA\Game;
use App\Services\Predictions\Basketball\BasketballHistoricalForecastArchive;

class NbaHistoricalForecastArchive
{
    /** @return array<string, mixed>|null */
    public function forGame(Game $game): ?array
    {
        return app(BasketballHistoricalForecastArchive::class)->forGame($game, 'nba');
    }
}
