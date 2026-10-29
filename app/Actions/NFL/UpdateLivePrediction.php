<?php

namespace App\Actions\NFL;

use App\Actions\Sports\AbstractFootballUpdateLivePrediction;
use App\Services\Predictions\LivePredictionStore;
use Illuminate\Database\Eloquent\Model;

class UpdateLivePrediction extends AbstractFootballUpdateLivePrediction
{
    /** Calculate from one game/prediction snapshot without writing to the database. */
    public function preview(object $game): ?array
    {
        $state = LiveGameState::fromGame($game);
        $prediction = $game instanceof Model ? app(LivePredictionStore::class)->baseline($game) : null;
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
        $prediction = $game instanceof Model ? app(LivePredictionStore::class)->baseline($game) : null;
        if (! $game instanceof Model) {
            return null;
        }
        $store = app(LivePredictionStore::class);
        if ($values === null) {
            $store->clear($game, static::class);

            return null;
        }
        $store->record($game, $prediction, $values, 'live', static::class);

        return $values;
    }
}
