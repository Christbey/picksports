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

        $fourthQuarterClutch = $this->games->filter(function ($game) {
            $team = $this->teamLinescores($game);
            $opp = $this->opponentLinescores($game);

            $teamThreeQuarters = ($team[0] ?? 0) + ($team[1] ?? 0) + ($team[2] ?? 0);
            $oppThreeQuarters = ($opp[0] ?? 0) + ($opp[1] ?? 0) + ($opp[2] ?? 0);

            $wasTight = abs($teamThreeQuarters - $oppThreeQuarters) <= 10;
            $teamQ4 = $team[3] ?? 0;
            $oppQ4 = $opp[3] ?? 0;

            return $wasTight && $teamQ4 > $oppQ4;
        })->count();

        if ($fourthQuarterClutch >= 3) {
            $messages[] = "The {$this->teamAbbr} have won the 4th quarter in close games {$fourthQuarterClutch} times";
        }

        $oneScoreGames = $this->games->filter(fn ($g) => abs($this->margin($g)) <= 8);
        $blowouts = $this->games->filter(fn ($g) => abs($this->margin($g)) >= 14);

        if ($oneScoreGames->count() >= $this->games->count() * 0.6) {
            $messages[] = "The {$this->teamAbbr} play in close games often ({$oneScoreGames->count()} of {$this->games->count()} games within 8 points)";
        } elseif ($blowouts->count() >= $this->games->count() * 0.4) {
            $messages[] = "The {$this->teamAbbr} are often involved in blowouts ({$blowouts->count()} of {$this->games->count()} games decided by 14+ points)";
        }

        return $messages;
    }
}
