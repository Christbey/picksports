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
        $count = $this->games->count();

        $firstHalfWins = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            $teamFirstHalf = ($team[0] ?? 0) + ($team[1] ?? 0);
            $oppFirstHalf = ($opp[0] ?? 0) + ($opp[1] ?? 0);

            return $teamFirstHalf > $oppFirstHalf;
        });

        if ($this->isSignificant($firstHalfWins)) {
            $messages[] = "The {$this->teamAbbr} have won the first half in {$firstHalfWins} of their last {$count} games";
        }

        $secondHalfWins = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            $teamSecondHalf = ($team[2] ?? 0) + ($team[3] ?? 0);
            $oppSecondHalf = ($opp[2] ?? 0) + ($opp[3] ?? 0);

            return $teamSecondHalf > $oppSecondHalf;
        });

        if ($this->isSignificant($secondHalfWins)) {
            $messages[] = "The {$this->teamAbbr} have won the second half in {$secondHalfWins} of their last {$count} games";
        }

        $avgFirstHalf = $this->games->avg(function ($game) {
            $team = $this->teamLinescores($game);

            return ($team[0] ?? 0) + ($team[1] ?? 0);
        });

        $avgSecondHalf = $this->games->avg(function ($game) {
            $team = $this->teamLinescores($game);

            return ($team[2] ?? 0) + ($team[3] ?? 0);
        });

        if ($avgSecondHalf > $avgFirstHalf * 1.2) {
            $messages[] = "The {$this->teamAbbr} are a second-half team, averaging ".number_format($avgSecondHalf, 1).' vs '.number_format($avgFirstHalf, 1).' in the first half';
        } elseif ($avgFirstHalf > $avgSecondHalf * 1.2) {
            $messages[] = "The {$this->teamAbbr} are a first-half team, averaging ".number_format($avgFirstHalf, 1).' vs '.number_format($avgSecondHalf, 1).' in the second half';
        }

        return $messages;
    }
}
