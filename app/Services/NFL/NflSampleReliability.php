<?php

namespace App\Services\NFL;

class NflSampleReliability
{
    /** Conservative prior strength, not a weight fitted to September 2026 results. */
    public function weight(float $maximumWeight, int $homeGames, int $awayGames): float
    {
        $sample = max(0, min($homeGames, $awayGames));
        $priorGames = max(1, (int) config('nfl.predictions.sample_reliability.prior_games', 8));

        return max(0.0, min(1.0, $maximumWeight)) * $sample / ($sample + $priorGames);
    }
}
