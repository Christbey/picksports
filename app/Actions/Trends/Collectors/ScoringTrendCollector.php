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
        $avg = $this->games->avg(fn ($g) => $this->teamScore($g));
        $avgAllowed = $this->games->avg(fn ($g) => $this->opponentScore($g));
        $messages[] = "The {$this->teamAbbr} average ".number_format($avg, 1)." {$unit} per game";
        $messages[] = "The {$this->teamAbbr} allow ".number_format($avgAllowed, 1)." {$unit} per game";
        $thresholdMessages = [];

        foreach ($thresholds as $threshold) {
            $gamesOver = $this->countWhere(fn ($g) => $this->teamScore($g) >= $threshold);

            if ($this->isSignificant($gamesOver)) {
                // Equal hit counts are nested evidence from the same games.
                $thresholdMessages[$gamesOver] = "The {$this->teamAbbr} have scored {$threshold}+ {$unit} in {$gamesOver} of their last {$count} games";
            }
        }

        return [...$messages, ...array_values($thresholdMessages)];
    }
}
