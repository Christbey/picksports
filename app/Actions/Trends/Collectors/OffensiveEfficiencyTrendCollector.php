<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class OffensiveEfficiencyTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'offensive_efficiency';
    }

    public function collect(): array
    {
        $messages = [];

        $gamesWithStats = $this->games->filter(fn ($g) => $this->teamStats($g) !== null);

        if ($gamesWithStats->isEmpty()) {
            return $messages;
        }

        $avgYards = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->total_yards ?? 0);
        if ($avgYards > 0) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgYards, 1).' total yards per game';
        }

        $avgPassYards = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->passing_yards ?? 0);
        $avgRushYards = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->rushing_yards ?? 0);

        if ($avgPassYards > 0 && $avgRushYards > 0) {
            if ($avgPassYards > $avgRushYards * 1.5) {
                $messages[] = "The {$this->teamAbbr} are pass-heavy, averaging ".number_format($avgPassYards, 1).' passing yards vs '.number_format($avgRushYards, 1).' rushing yards';
            } elseif ($avgRushYards > $avgPassYards * 0.8) {
                $messages[] = "The {$this->teamAbbr} have a balanced attack with ".number_format($avgPassYards, 1).' passing and '.number_format($avgRushYards, 1).' rushing yards per game';
            }
        }

        $avgFirstDowns = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->first_downs ?? 0);
        if ($avgFirstDowns >= 15) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgFirstDowns, 1).' first downs per game';
        }

        $turnoversLow = $gamesWithStats->filter(function ($g) {
            $stats = $this->teamStats($g);
            $turnovers = ($stats->fumbles_lost ?? 0) + ($stats->interceptions_thrown ?? 0);

            return $turnovers <= 1;
        })->count();

        if ($this->isSignificant($turnoversLow, $gamesWithStats->count())) {
            $messages[] = "The {$this->teamAbbr} have had 1 or fewer turnovers in {$turnoversLow} of their last {$gamesWithStats->count()} games";
        }

        return $messages;
    }
}
