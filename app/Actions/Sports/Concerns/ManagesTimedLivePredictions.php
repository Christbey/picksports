<?php

namespace App\Actions\Sports\Concerns;

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
        $prediction->update([
            'live_predicted_spread' => null,
            'live_win_probability' => null,
            'live_predicted_total' => null,
            $remainingField => null,
            'live_updated_at' => null,
        ]);
    }
}
