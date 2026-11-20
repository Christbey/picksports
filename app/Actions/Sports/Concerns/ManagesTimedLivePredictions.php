<?php

namespace App\Actions\Sports\Concerns;

use App\Services\Predictions\LivePredictionStore;

trait ManagesTimedLivePredictions
{
    protected function parseGameClock(?string $gameClock): int
    {
        if (! $gameClock) {
            return 0;
        }

        $parts = explode(':', $gameClock);

        if (count($parts) !== 2) {
            return 0;
        }

        $minutes = (int) $parts[0];
        $seconds = (int) $parts[1];

        return ($minutes * 60) + $seconds;
    }

    protected function clearTimedLivePrediction(object $prediction, string $remainingField = 'live_seconds_remaining'): void
    {
        $store = app(LivePredictionStore::class);
        $store->clear($store->gameFor($prediction), static::class);
    }
}
