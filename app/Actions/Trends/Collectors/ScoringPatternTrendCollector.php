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

            $teamFirstHalf = ($team[0] ?? 0) + ($team[1] ?? 0);
            $oppFirstHalf = ($opp[0] ?? 0) + ($opp[1] ?? 0);

            return $teamFirstHalf < $oppFirstHalf && $this->won($game);
        });

        if ($comebacks >= 3) {
            $messages[] = "The {$this->teamAbbr} have come back from halftime deficits to win {$comebacks} times in their last {$count} games";
        }

        $blownLeads = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            $teamFirstHalf = ($team[0] ?? 0) + ($team[1] ?? 0);
            $oppFirstHalf = ($opp[0] ?? 0) + ($opp[1] ?? 0);

            return $teamFirstHalf > $oppFirstHalf && ! $this->won($game);
        });

        if ($blownLeads >= 3) {
            $messages[] = "The {$this->teamAbbr} have blown halftime leads {$blownLeads} times in their last {$count} games";
        }

        $scoredEveryQuarter = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);

            return count($team) >= 4 &&
                   ($team[0] ?? 0) > 0 &&
                   ($team[1] ?? 0) > 0 &&
                   ($team[2] ?? 0) > 0 &&
                   ($team[3] ?? 0) > 0;
        });

        if ($this->isSignificant($scoredEveryQuarter)) {
            $messages[] = "The {$this->teamAbbr} have scored in every quarter in {$scoredEveryQuarter} of their last {$count} games";
        }

        $fastStarts = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return ($team[0] ?? 0) > ($opp[0] ?? 0) * 1.5 && ($team[0] ?? 0) >= 7;
        });

        if ($fastStarts >= 3) {
            $messages[] = "The {$this->teamAbbr} have started fast (dominating Q1) in {$fastStarts} of their last {$count} games";
        }

        return $messages;
    }
}
