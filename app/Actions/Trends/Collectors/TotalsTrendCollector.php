<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class TotalsTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'totals';
    }

    public function collect(): array
    {
        $messages = [];
        $count = $this->games->count();
        $unit = $this->scoringUnit();

        $avgTotal = $this->games->avg(fn ($g) => $this->totalPoints($g));
        $messages[] = "Games involving the {$this->teamAbbr} average ".number_format($avgTotal, 1)." total {$unit}";

        $gamesWithPrediction = $this->games->filter(fn ($g) => $g->relationLoaded('prediction') && $g->prediction);

        if ($gamesWithPrediction->isNotEmpty()) {
            $overs = $gamesWithPrediction->filter(function ($g) {
                $total = $g->prediction->predicted_total ?? null;

                return $total && $this->totalPoints($g) > $total;
            })->count();

            $unders = $gamesWithPrediction->filter(function ($g) {
                $total = $g->prediction->predicted_total ?? null;

                return $total && $this->totalPoints($g) < $total;
            })->count();

            $ouTotal = $overs + $unders;

            if ($ouTotal >= 5) {
                if ($overs > $unders && $this->percentage($overs, $ouTotal) >= 60) {
                    $messages[] = "The {$this->teamAbbr} have gone OVER in {$overs} of their last {$ouTotal} games with totals";
                } elseif ($unders > $overs && $this->percentage($unders, $ouTotal) >= 60) {
                    $messages[] = "The {$this->teamAbbr} have gone UNDER in {$unders} of their last {$ouTotal} games with totals";
                }
            }
        }

        $highScoringGames = $this->countWhere(fn ($g) => $this->totalPoints($g) >= $avgTotal * 1.15);
        if ($this->isSignificant($highScoringGames)) {
            $messages[] = "The {$this->teamAbbr} are involved in high-scoring games {$highScoringGames} of their last {$count} games";
        }

        return $messages;
    }
}
