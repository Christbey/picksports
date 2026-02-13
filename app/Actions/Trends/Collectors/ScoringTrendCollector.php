<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class ScoringTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'scoring';
    }

    public function collect(): array
    {
        $messages = [];
        $thresholds = $this->config('scoring', [21, 24, 28, 35]);
        $count = $this->games->count();
        $unit = $this->scoringUnit();

        foreach ($thresholds as $threshold) {
            $gamesOver = $this->countWhere(fn ($g) => $this->teamScore($g) >= $threshold);

            if ($this->isSignificant($gamesOver)) {
                $messages[] = "The {$this->teamAbbr} have scored {$threshold}+ {$unit} in {$gamesOver} of their last {$count} games";
            }
        }

        $avg = $this->games->avg(fn ($g) => $this->teamScore($g));
        $messages[] = "The {$this->teamAbbr} average ".number_format($avg, 1)." {$unit} per game";

        $avgAllowed = $this->games->avg(fn ($g) => $this->opponentScore($g));
        $messages[] = "The {$this->teamAbbr} allow ".number_format($avgAllowed, 1)." {$unit} per game";

        return $messages;
    }
}
