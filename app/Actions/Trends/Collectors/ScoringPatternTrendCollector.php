<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class ScoringPatternTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'scoring_patterns';
    }

    public function collect(): array
    {
        $messages = [];
        $count = $this->games->count();

        $comebacks = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            $teamFirstSegment = $this->firstSegmentTotal($team);
            $oppFirstSegment = $this->firstSegmentTotal($opp);

            return $teamFirstSegment < $oppFirstSegment && $this->won($game);
        });

        if ($comebacks >= 3) {
            $messages[] = "The {$this->teamAbbr} have come back from {$this->segmentLabel()} deficits to win {$comebacks} times in their last {$count} games";
        }

        $blownLeads = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            $teamFirstSegment = $this->firstSegmentTotal($team);
            $oppFirstSegment = $this->firstSegmentTotal($opp);

            return $teamFirstSegment > $oppFirstSegment && ! $this->won($game);
        });

        if ($blownLeads >= 3) {
            $messages[] = "The {$this->teamAbbr} have blown {$this->segmentLabel()} leads {$blownLeads} times in their last {$count} games";
        }

        $scoredEveryQuarter = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);

            if ($this->isBaseball()) {
                return collect($team)->filter(fn ($score) => (int) $score > 0)->count() >= 5;
            }

            if ($this->isCollegeBasketball()) {
                return count($team) >= 2
                    && ($team[0] ?? 0) > 0
                    && ($team[1] ?? 0) > 0;
            }

            return count($team) >= 4
                && ($team[0] ?? 0) > 0
                && ($team[1] ?? 0) > 0
                && ($team[2] ?? 0) > 0
                && ($team[3] ?? 0) > 0;
        });

        if ($this->isSignificant($scoredEveryQuarter)) {
            $periodTerm = match (true) {
                $this->isBaseball() => '5+ innings',
                $this->isCollegeBasketball() => 'every half',
                default => 'every quarter',
            };
            $messages[] = "The {$this->teamAbbr} have scored in {$periodTerm} in {$scoredEveryQuarter} of their last {$count} games";
        }

        $fastStarts = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return ($team[0] ?? 0) > ($opp[0] ?? 0) * 1.5
                && ($team[0] ?? 0) >= $this->fastStartMinimum();
        });

        if ($fastStarts >= 3) {
            $periodLabel = match (true) {
                $this->isBaseball() => 'the 1st inning',
                $this->isCollegeBasketball() => '1H',
                default => 'Q1',
            };
            $messages[] = "The {$this->teamAbbr} have started fast (dominating {$periodLabel}) in {$fastStarts} of their last {$count} games";
        }

        return $messages;
    }

    /**
     * @param  array<int, int>  $lines
     */
    private function firstSegmentTotal(array $lines): int
    {
        if ($this->isBaseball()) {
            return collect($lines)->take(5)->sum();
        }

        if ($this->isCollegeBasketball()) {
            return (int) ($lines[0] ?? 0);
        }

        return (int) (($lines[0] ?? 0) + ($lines[1] ?? 0));
    }

    private function segmentLabel(): string
    {
        return $this->isBaseball() ? 'first-five-innings' : 'halftime';
    }

    private function fastStartMinimum(): int
    {
        return $this->isBaseball() ? 2 : 7;
    }
}
