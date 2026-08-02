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
        $periodLabel = $this->firstPeriodLabel();

        $gamesWithFirstPeriod = $this->games->filter(function ($game): bool {
            return array_key_exists(0, $this->teamLinescores($game))
                && array_key_exists(0, $this->opponentLinescores($game));
        });
        $count = $gamesWithFirstPeriod->count();

        if ($count === 0) {
            return [];
        }

        $scoredFirst = $gamesWithFirstPeriod->filter(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return $team[0] > $opp[0];
        })->count();

        if ($this->isSignificant($scoredFirst)) {
            $messages[] = "The {$this->teamAbbr} have outscored opponents in {$periodLabel} in {$scoredFirst} of their last {$count} games";
        }

        $scoredFirstWins = $gamesWithFirstPeriod->filter(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return ($team[0] ?? 0) > ($opp[0] ?? 0) && $this->won($game);
        })->count();

        if ($scoredFirst >= 3 && $scoredFirstWins >= 2) {
            $pct = $this->percentage($scoredFirstWins, $scoredFirst);
            $messages[] = "When the {$this->teamAbbr} outscore opponents in {$periodLabel}, they win {$pct}% of the time ({$scoredFirstWins}/{$scoredFirst})";
        }

        $trailingGames = $gamesWithFirstPeriod->filter(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            return $team[0] < $opp[0];
        });
        $scoredLastWins = $trailingGames->filter(fn ($game): bool => $this->won($game))->count();

        $scoredLast = $trailingGames->count();
        if ($scoredLast >= 3 && $scoredLastWins >= 2) {
            $pct = $this->percentage($scoredLastWins, $scoredLast);
            $messages[] = "When the {$this->teamAbbr} trail after {$periodLabel}, they still win {$pct}% of the time ({$scoredLastWins}/{$scoredLast})";
        }

        return $messages;
    }
}
