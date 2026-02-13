<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class DefensivePerformanceTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'defensive_performance';
    }

    public function collect(): array
    {
        $messages = [];
        $count = $this->games->count();

        $avgAllowed = $this->games->avg(fn ($g) => $this->opponentScore($g));
        $messages[] = "The {$this->teamAbbr} defense allows ".number_format($avgAllowed, 1).' points per game';

        $scoringThresholds = $this->config('scoring', [21, 24, 28, 35]);
        $lowThreshold = $scoringThresholds[0] ?? 21;

        $heldUnder = $this->countWhere(fn ($g) => $this->opponentScore($g) < $lowThreshold);

        if ($this->isSignificant($heldUnder)) {
            $messages[] = "The {$this->teamAbbr} have held opponents under {$lowThreshold} points in {$heldUnder} of their last {$count} games";
        }

        $shutoutQuarters = $this->games->sum(function ($game) {
            $opp = $this->opponentLinescores($game);
            $shutouts = 0;
            foreach ($opp as $score) {
                if ($score === 0) {
                    $shutouts++;
                }
            }

            return $shutouts;
        });

        if ($shutoutQuarters >= $count) {
            $messages[] = "The {$this->teamAbbr} defense has recorded {$shutoutQuarters} shutout quarters in their last {$count} games";
        }

        $gamesWithStats = $this->games->filter(fn ($g) => $this->opponentStats($g) !== null);

        if ($gamesWithStats->count() >= 3) {
            $avgYardsAllowed = $gamesWithStats->avg(fn ($g) => $this->opponentStats($g)->total_yards ?? 0);
            if ($avgYardsAllowed > 0) {
                $messages[] = "The {$this->teamAbbr} defense allows ".number_format($avgYardsAllowed, 1).' total yards per game';
            }
        }

        return $messages;
    }
}
