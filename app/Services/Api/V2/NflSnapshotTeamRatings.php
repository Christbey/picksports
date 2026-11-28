<?php

namespace App\Services\Api\V2;

use App\Models\CanonicalPrediction;
use App\Models\NFL\Game;

/** Display the captured context only; never substitute current team ratings. */
class NflSnapshotTeamRatings
{
    public function forPrediction(CanonicalPrediction $prediction, ?Game $game): array
    {
        $ratings = ['home' => null, 'away' => null];
        if ($prediction->sport !== 'nfl' || $game === null) {
            return $ratings;
        }
        $inputs = (array) $prediction->calculationRun?->inputSnapshot?->inputs;
        foreach (['home', 'away'] as $side) {
            $teamId = $this->teamId($game->{$side.'_team_id'});
            $value = data_get($inputs, $side.'.elo');
            if ($teamId !== null && $this->teamId(data_get($inputs, $side.'.team_id')) === $teamId
                && is_numeric($value) && is_finite((float) $value) && (float) $value > 0) {
                $ratings[$side] = (float) $value;
            }
        }

        return $ratings;
    }

    private function teamId(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            ? $value
            : null;
    }
}
