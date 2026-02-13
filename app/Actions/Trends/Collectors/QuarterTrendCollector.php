<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class QuarterTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'quarters';
    }

    public function collect(): array
    {
        $messages = [];
        $count = $this->games->count();
        $quarterNames = ['Q1', 'Q2', 'Q3', 'Q4'];

        // Calculate average points per quarter (scored and allowed)
        $quarterTotals = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
        $quarterAllowed = [0 => 0, 1 => 0, 2 => 0, 3 => 0];
        $gamesWithLinescores = 0;

        foreach ($this->games as $game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            if (! empty($team) && ! empty($opp)) {
                $gamesWithLinescores++;
                foreach ([0, 1, 2, 3] as $q) {
                    $quarterTotals[$q] += $team[$q] ?? 0;
                    $quarterAllowed[$q] += $opp[$q] ?? 0;
                }
            }
        }

        if ($gamesWithLinescores > 0) {
            $avgScored = [];
            $avgAllowed = [];
            foreach ([0, 1, 2, 3] as $q) {
                $avgScored[$q] = $quarterTotals[$q] / $gamesWithLinescores;
                $avgAllowed[$q] = $quarterAllowed[$q] / $gamesWithLinescores;
            }

            // Only show if we have actual scoring data (not all zeros)
            $totalScored = array_sum($avgScored);
            if ($totalScored > 0) {
                // Add average points per quarter message
                $messages[] = "The {$this->teamAbbr} average ".
                    number_format($avgScored[0], 1).' pts in Q1, '.
                    number_format($avgScored[1], 1).' in Q2, '.
                    number_format($avgScored[2], 1).' in Q3, '.
                    number_format($avgScored[3], 1).' in Q4';

                $messages[] = "The {$this->teamAbbr} allow ".
                    number_format($avgAllowed[0], 1).' pts in Q1, '.
                    number_format($avgAllowed[1], 1).' in Q2, '.
                    number_format($avgAllowed[2], 1).' in Q3, '.
                    number_format($avgAllowed[3], 1).' in Q4';

                // Find best and worst scoring quarters
                $maxScored = max($avgScored);
                $minScored = min($avgScored);
                $bestQ = array_search($maxScored, $avgScored);
                $worstQ = array_search($minScored, $avgScored);

                if ($maxScored > $minScored + 1) {
                    $messages[] = "The {$this->teamAbbr} score most in {$quarterNames[$bestQ]} (".number_format($maxScored, 1)." avg) and least in {$quarterNames[$worstQ]} (".number_format($minScored, 1).' avg)';
                }
            }
        }

        // Quarter wins
        foreach ([0, 1, 2, 3] as $quarterIndex) {
            $wins = $this->countWhere(function ($game) use ($quarterIndex) {
                $team = $this->teamLinescores($game);
                $opp = $this->opponentLinescores($game);

                return ($team[$quarterIndex] ?? 0) > ($opp[$quarterIndex] ?? 0);
            });

            if ($this->isSignificant($wins)) {
                $quarterLabel = $quarterNames[$quarterIndex] ?? 'Q'.($quarterIndex + 1);
                $messages[] = "The {$this->teamAbbr} have won {$quarterLabel} in {$wins} of their last {$count} games";
            }
        }

        $scoredFirst = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return ($team[0] ?? 0) > 0 && ($opp[0] ?? 0) === 0 ||
                   ($team[0] ?? 0) > ($opp[0] ?? 0);
        });

        if ($this->isSignificant($scoredFirst)) {
            $messages[] = "The {$this->teamAbbr} have outscored opponents in Q1 in {$scoredFirst} of their last {$count} games";
        }

        return $messages;
    }
}
