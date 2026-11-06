<?php

namespace App\Services\NFL\Predictions;

use App\Models\NFL\Game;
use App\Services\Predictions\PregameMarketSnapshot;
use Carbon\CarbonImmutable;

class NflPregameMarketSnapshot extends PregameMarketSnapshot
{
    public function capture(Game $game, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt): array
    {
        return $this->captureForGame($game, $capturedAt, $cutoffAt);
    }

    protected function sport(): string
    {
        return 'nfl';
    }

    protected function maximumQuoteAgeMinutes(): int
    {
        return max(1, (int) config('nfl_research.market_freshness_minutes', 2160));
    }
}
