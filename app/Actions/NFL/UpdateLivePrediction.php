<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractFootballUpdateLivePrediction;

class UpdateLivePrediction extends AbstractFootballUpdateLivePrediction
{
    /** Calculate from one game/prediction snapshot without writing to the database. */
    public function preview(object $game): ?array
    {
        $state = LiveGameState::fromGame($game);
        $prediction = $game->prediction;
        if ($state === null || ! $prediction) {
            return null;
        }

        $fraction = $state['seconds_elapsed'] / $state['effective_length'];

        return [
            'live_predicted_spread' => round($this->calculateLiveSpread(
                $state['margin'], $state['seconds_remaining'], $fraction, $prediction->predicted_spread ?? 0
            ), 1),
            'live_win_probability' => round(LiveGameState::homeProbability(
                $state['margin'], $state['seconds_remaining'], $prediction->win_probability ?? 0.5, $game->period > 4
            ), 3),
            'live_predicted_total' => round($this->calculateLiveTotal(
                $state['total'], $state['seconds_elapsed'], $state['seconds_remaining'],
                $state['effective_length'], $prediction->predicted_total ?? self::DEFAULT_PRE_GAME_TOTAL
            ), 1),
            'live_seconds_remaining' => $state['seconds_remaining'],
        ];
    }

    public function execute(object $game): ?array
    {
        $values = $this->preview($game);
        $prediction = $game->prediction;
        if ($values === null) {
            if ($prediction && collect([
                $prediction->live_updated_at,
                $prediction->live_seconds_remaining,
                $prediction->live_win_probability,
                $prediction->live_predicted_spread,
                $prediction->live_predicted_total,
            ])->contains(fn ($value) => $value !== null)) {
                $this->clearLivePrediction($prediction);
            }

            return null;
        }

        $prediction->update([...$values, 'live_updated_at' => now()]);

        return $values;
    }
}
