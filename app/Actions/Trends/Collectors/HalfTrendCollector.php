<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class HalfTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'halves';
    }

    public function collect(): array
    {
        $messages = [];
        $gamesWithLinescores = $this->games->filter(function ($game) {
            return ! empty($this->teamLinescores($game)) && ! empty($this->opponentLinescores($game));
        });

        if ($gamesWithLinescores->isEmpty()) {
            return $messages;
        }

        $count = $gamesWithLinescores->count();

        $firstHalfWins = $gamesWithLinescores->filter(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            $teamFirstHalf = $this->firstHalfScore($team);
            $oppFirstHalf = $this->firstHalfScore($opp);

            return $teamFirstHalf > $oppFirstHalf;
        })->count();

        if ($this->isSignificant($firstHalfWins, $count)) {
            $messages[] = "The {$this->teamAbbr} have won the first half in {$firstHalfWins} of their last {$count} games";
        }

        $secondHalfWins = $gamesWithLinescores->filter(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            $teamSecondHalf = $this->secondHalfScore($team);
            $oppSecondHalf = $this->secondHalfScore($opp);

            return $teamSecondHalf > $oppSecondHalf;
        })->count();

        if ($this->isSignificant($secondHalfWins, $count)) {
            $messages[] = "The {$this->teamAbbr} have won the second half in {$secondHalfWins} of their last {$count} games";
        }

        $avgFirstHalf = $gamesWithLinescores->avg(function ($game) {
            $team = $this->teamLinescores($game);

            return $this->firstHalfScore($team);
        });

        $avgSecondHalf = $gamesWithLinescores->avg(function ($game) {
            $team = $this->teamLinescores($game);

            return $this->secondHalfScore($team);
        });

        if ($avgSecondHalf > $avgFirstHalf * 1.2) {
            $messages[] = "The {$this->teamAbbr} are a second-half team, averaging ".number_format($avgSecondHalf, 1).' vs '.number_format($avgFirstHalf, 1).' in the first half';
        } elseif ($avgFirstHalf > $avgSecondHalf * 1.2) {
            $messages[] = "The {$this->teamAbbr} are a first-half team, averaging ".number_format($avgFirstHalf, 1).' vs '.number_format($avgSecondHalf, 1).' in the second half';
        }

        return $messages;
    }

    /**
     * @param  array<int, int>  $lines
     */
    private function firstHalfScore(array $lines): int
    {
        if ($this->isCollegeBasketball()) {
            return (int) ($lines[0] ?? 0);
        }

        return (int) (($lines[0] ?? 0) + ($lines[1] ?? 0));
    }

    /**
     * @param  array<int, int>  $lines
     */
    private function secondHalfScore(array $lines): int
    {
        if ($this->isCollegeBasketball()) {
            return (int) ($lines[1] ?? 0);
        }

        return (int) (($lines[2] ?? 0) + ($lines[3] ?? 0));
    }
}
