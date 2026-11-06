<?php

namespace App\Services\CBB\Predictions;

use App\Models\CBB\Game;
use App\Services\Predictions\PregameMarketSnapshot;
use Carbon\CarbonImmutable;

class CbbPregameMarketSnapshot extends PregameMarketSnapshot
{
    public function capture(Game $game, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt): array
    {
        return $this->captureForGame($game, $capturedAt, $cutoffAt);
    }

    protected function requiresBothMarkets(): bool
    {
        return false;
    }

    protected function sport(): string
    {
        return 'cbb';
    }

    protected function maximumQuoteAgeMinutes(): int
    {
        return max(1, (int) config('cbb.prediction.pregame_market.maximum_quote_age_minutes', 2160));
    }
}
