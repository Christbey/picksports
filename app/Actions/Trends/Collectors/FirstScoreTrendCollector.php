<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class FirstScoreTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'first_score';
    }

    public function collect(): array
    {
        $messages = [];
        $count = $this->games->count();
        $periodLabel = $this->firstPeriodLabel();

        $scoredFirst = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return ($team[0] ?? 0) > ($opp[0] ?? 0);
        });

        if ($this->isSignificant($scoredFirst)) {
            $messages[] = "The {$this->teamAbbr} have outscored opponents in {$periodLabel} in {$scoredFirst} of their last {$count} games";
        }

        $scoredFirstWins = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return ($team[0] ?? 0) > ($opp[0] ?? 0) && $this->won($game);
        });

        if ($scoredFirst >= 3 && $scoredFirstWins >= 2) {
            $pct = $this->percentage($scoredFirstWins, $scoredFirst);
            $messages[] = "When the {$this->teamAbbr} outscore opponents in {$periodLabel}, they win {$pct}% of the time ({$scoredFirstWins}/{$scoredFirst})";
        }

        $scoredLastWins = $this->countWhere(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return ($team[0] ?? 0) < ($opp[0] ?? 0) && $this->won($game);
        });

        $scoredLast = $count - $scoredFirst;
        if ($scoredLast >= 3 && $scoredLastWins >= 2) {
            $pct = $this->percentage($scoredLastWins, $scoredLast);
            $messages[] = "When the {$this->teamAbbr} trail after {$periodLabel}, they still win {$pct}% of the time ({$scoredLastWins}/{$scoredLast})";
        }

        return $messages;
    }
}
