<?php

namespace App\Services\CBB\Predictions;

use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\SportEventResult;

class CbbForecastResultIdentity
{
    /** @return array{home_score:int,away_score:int,home_team_id:int,away_team_id:int} */
    public function scores(CanonicalPrediction $prediction, SportEventResult $result): array
    {
        $inputs = (array) $prediction->calculationRun?->inputSnapshot?->inputs;
        $home = (int) data_get($inputs, 'home.team_id');
        $away = (int) data_get($inputs, 'away.team_id');
        $resultHome = (int) data_get($result->metadata, 'home_team_id');
        $resultAway = (int) data_get($result->metadata, 'away_team_id');

        if ($resultHome <= 0 || $resultAway <= 0) {
            // Legacy results did not freeze participant IDs. Only use current slots
            // when their final score still exactly matches this immutable result.
            $game = $prediction->sportEvent?->cbbGame;
            if ($game === null || $game->status !== 'STATUS_FINAL'
                || (int) $game->home_score !== $result->home_score
                || (int) $game->away_score !== $result->away_score) {
                throw new PredictionLifecycleException('CBB result participant identity is unavailable.');
            }
            $resultHome = (int) $game->home_team_id;
            $resultAway = (int) $game->away_team_id;
        }

        if ($home <= 0 || $away <= 0 || $home === $away || $resultHome === $resultAway) {
            throw new PredictionLifecycleException('CBB frozen forecast participant identity is unavailable.');
        }

        if ($home === $resultHome && $away === $resultAway) {
            return ['home_score' => $result->home_score, 'away_score' => $result->away_score,
                'home_team_id' => $home, 'away_team_id' => $away];
        }
        if ($home === $resultAway && $away === $resultHome) {
            return ['home_score' => $result->away_score, 'away_score' => $result->home_score,
                'home_team_id' => $home, 'away_team_id' => $away];
        }

        throw new PredictionLifecycleException('CBB result participants differ from the frozen forecast; evaluation held.');
    }
}
