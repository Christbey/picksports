<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class ClutchPerformanceTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'clutch_performance';
    }

    public function collect(): array
    {
        $messages = [];
        $closeMargin = $this->config('close_game_margin', 7);
        $oneScoreThreshold = $closeMargin + ($this->isBaseball() ? 0 : 1);
        $marginBuckets = $this->config('margin', [3, 7, 10, 14]);
        $blowoutThreshold = (int) (is_array($marginBuckets) ? end($marginBuckets) : 14);
        $blowoutThreshold = max($blowoutThreshold, $closeMargin + 2);

        $closeGames = $this->games->filter(fn ($g) => abs($this->margin($g)) <= $closeMargin);

        if ($closeGames->count() >= 3) {
            $closeWins = $closeGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($closeWins, $closeGames->count())} in close games (decided by {$closeMargin} points or less)";

            $clutchPct = $this->percentage($closeWins, $closeGames->count());
            if ($clutchPct >= 70) {
                $messages[] = "The {$this->teamAbbr} are clutch performers, winning {$clutchPct}% of close games";
            } elseif ($clutchPct <= 30) {
                $messages[] = "The {$this->teamAbbr} struggle in close games, winning only {$clutchPct}% of them";
            }
        }

        if ($this->isBasketball() || $this->isFootball()) {
            $latePeriodClutch = $this->games->filter(function ($game) use ($closeMargin) {
                $team = $this->teamLinescores($game);
                $opp = $this->opponentLinescores($game);

                if ($this->isCollegeBasketball()) {
                    $wasTight = abs(($team[0] ?? 0) - ($opp[0] ?? 0)) <= $closeMargin;

                    return $wasTight && ($team[1] ?? 0) > ($opp[1] ?? 0);
                }

                $teamThreeQuarters = ($team[0] ?? 0) + ($team[1] ?? 0) + ($team[2] ?? 0);
                $oppThreeQuarters = ($opp[0] ?? 0) + ($opp[1] ?? 0) + ($opp[2] ?? 0);
                $wasTight = abs($teamThreeQuarters - $oppThreeQuarters) <= 10;

                return $wasTight && ($team[3] ?? 0) > ($opp[3] ?? 0);
            })->count();

            if ($latePeriodClutch >= 3) {
                $periodLabel = $this->isCollegeBasketball() ? '2nd half' : '4th quarter';
                $messages[] = "The {$this->teamAbbr} have won the {$periodLabel} in close games {$latePeriodClutch} times";
            }
        }

        $oneScoreGames = $this->games->filter(fn ($g) => abs($this->margin($g)) <= $oneScoreThreshold);
        $blowouts = $this->games->filter(fn ($g) => abs($this->margin($g)) >= $blowoutThreshold);

        if ($oneScoreGames->count() >= $this->games->count() * 0.6) {
            $messages[] = "The {$this->teamAbbr} play in close games often ({$oneScoreGames->count()} of {$this->games->count()} games within {$oneScoreThreshold} {$this->scoringUnit()})";
        } elseif ($blowouts->count() >= $this->games->count() * 0.4) {
            $messages[] = "The {$this->teamAbbr} are often involved in blowouts ({$blowouts->count()} of {$this->games->count()} games decided by {$blowoutThreshold}+ {$this->scoringUnit()})";
        }

        return $messages;
    }
}
