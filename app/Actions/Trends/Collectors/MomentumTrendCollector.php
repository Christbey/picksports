<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class MomentumTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'momentum';
    }

    public function collect(): array
    {
        $messages = [];

        $recentGames = $this->games->sortByDesc('game_date')->take(5);
        $recentWins = $recentGames->filter(fn ($g) => $this->won($g))->count();
        $recentCount = $recentGames->count();

        if ($recentCount >= 3) {
            if ($recentWins >= 4) {
                $messages[] = "The {$this->teamAbbr} are hot, going {$recentWins}-".($recentCount - $recentWins).' in their last '.$recentCount.' games';
            } elseif ($recentWins <= 1) {
                $messages[] = "The {$this->teamAbbr} are struggling, going {$recentWins}-".($recentCount - $recentWins).' in their last '.$recentCount.' games';
            }
        }

        if ($this->games->count() >= 5) {
            $recentAvg = $recentGames->avg(fn ($g) => $this->margin($g));
            $overallAvg = $this->games->avg(fn ($g) => $this->margin($g));

            if ($recentAvg > $overallAvg + 5) {
                $messages[] = "The {$this->teamAbbr} are trending up, with a recent average margin of ".number_format($recentAvg, 1).' vs '.number_format($overallAvg, 1).' overall';
            } elseif ($recentAvg < $overallAvg - 5) {
                $messages[] = "The {$this->teamAbbr} are trending down, with a recent average margin of ".number_format($recentAvg, 1).' vs '.number_format($overallAvg, 1).' overall';
            }
        }

        $scoringImproving = $this->isImprovingTrend(fn ($g) => $this->teamScore($g));
        if ($scoringImproving === true) {
            $messages[] = "The {$this->teamAbbr} scoring has been improving over their recent games";
        } elseif ($scoringImproving === false) {
            $messages[] = "The {$this->teamAbbr} scoring has been declining over their recent games";
        }

        return $messages;
    }

    /**
     * @param  callable(object): float  $valueGetter
     */
    protected function isImprovingTrend(callable $valueGetter): ?bool
    {
        $sortedGames = $this->games->sortBy('game_date')->values();
        if ($sortedGames->count() < 6) {
            return null;
        }

        $firstHalf = $sortedGames->take(intval($sortedGames->count() / 2));
        $secondHalf = $sortedGames->skip(intval($sortedGames->count() / 2));

        $firstAvg = $firstHalf->avg($valueGetter);
        $secondAvg = $secondHalf->avg($valueGetter);

        if ($secondAvg > $firstAvg * 1.1) {
            return true;
        } elseif ($secondAvg < $firstAvg * 0.9) {
            return false;
        }

        return null;
    }
}
