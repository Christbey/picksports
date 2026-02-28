<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class OffensiveEfficiencyTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'offensive_efficiency';
    }

    public function collect(): array
    {
        if ($this->isBasketball()) {
            return $this->collectBasketball();
        }

        if ($this->isFootball()) {
            return $this->collectFootball();
        }

        if ($this->isBaseball()) {
            return $this->collectBaseball();
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function collectFootball(): array
    {
        $messages = [];

        $gamesWithStats = $this->games->filter(fn ($g) => $this->teamStats($g) !== null);

        if ($gamesWithStats->isEmpty()) {
            return $messages;
        }

        $avgYards = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->total_yards ?? 0);
        if ($avgYards > 0) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgYards, 1).' total yards per game';
        }

        $avgPassYards = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->passing_yards ?? 0);
        $avgRushYards = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->rushing_yards ?? 0);

        if ($avgPassYards > 0 && $avgRushYards > 0) {
            if ($avgPassYards > $avgRushYards * 1.5) {
                $messages[] = "The {$this->teamAbbr} are pass-heavy, averaging ".number_format($avgPassYards, 1).' passing yards vs '.number_format($avgRushYards, 1).' rushing yards';
            } elseif ($avgRushYards > $avgPassYards * 0.8) {
                $messages[] = "The {$this->teamAbbr} have a balanced attack with ".number_format($avgPassYards, 1).' passing and '.number_format($avgRushYards, 1).' rushing yards per game';
            }
        }

        $avgFirstDowns = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->first_downs ?? 0);
        if ($avgFirstDowns >= 15) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgFirstDowns, 1).' first downs per game';
        }

        $turnoversLow = $gamesWithStats->filter(function ($g) {
            $stats = $this->teamStats($g);
            $interceptions = $stats->interceptions_thrown ?? $stats->interceptions ?? 0;
            $turnovers = ($stats->fumbles_lost ?? 0) + $interceptions;

            return $turnovers <= 1;
        })->count();

        if ($this->isSignificant($turnoversLow, $gamesWithStats->count())) {
            $messages[] = "The {$this->teamAbbr} have had 1 or fewer turnovers in {$turnoversLow} of their last {$gamesWithStats->count()} games";
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    private function collectBasketball(): array
    {
        $messages = [];

        $gamesWithStats = $this->games->filter(fn ($g) => $this->teamStats($g) !== null);

        if ($gamesWithStats->isEmpty()) {
            return $messages;
        }

        $efficiencyValues = $gamesWithStats->map(function ($game) {
            $stats = $this->teamStats($game);
            $points = (float) ($stats->points ?? 0);
            $possessions = (float) ($stats->possessions ?? 0);

            if ($possessions <= 0) {
                return null;
            }

            return ($points / $possessions) * 100;
        })->filter(fn ($value) => $value !== null);

        if ($efficiencyValues->isNotEmpty()) {
            $avgOffEff = $efficiencyValues->avg();
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgOffEff, 1).' offensive rating (points per 100 possessions)';
        }

        $turnoversLow = $gamesWithStats->filter(function ($game) {
            $stats = $this->teamStats($game);
            $turnovers = (int) ($stats->turnovers ?? 0);

            return $turnovers > 0 && $turnovers <= 12;
        })->count();

        if ($this->isSignificant($turnoversLow, $gamesWithStats->count())) {
            $messages[] = "The {$this->teamAbbr} have committed 12 or fewer turnovers in {$turnoversLow} of their last {$gamesWithStats->count()} games";
        }

        $assistTurnoverRatios = $gamesWithStats->map(function ($game) {
            $stats = $this->teamStats($game);
            $assists = (float) ($stats->assists ?? 0);
            $turnovers = (float) ($stats->turnovers ?? 0);

            if ($turnovers <= 0) {
                return null;
            }

            return $assists / $turnovers;
        })->filter(fn ($ratio) => $ratio !== null);

        if ($assistTurnoverRatios->isNotEmpty()) {
            $avgAstTo = $assistTurnoverRatios->avg();
            if ($avgAstTo >= 1.4) {
                $messages[] = "The {$this->teamAbbr} are taking care of the ball with a ".number_format($avgAstTo, 2).' assist-to-turnover ratio';
            }
        }

        return $messages;
    }

    /**
     * @return array<int, string>
     */
    private function collectBaseball(): array
    {
        $messages = [];
        $gamesWithStats = $this->games->filter(fn ($g) => $this->teamStats($g) !== null);

        if ($gamesWithStats->isEmpty()) {
            return $messages;
        }

        $avgRuns = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->runs ?? 0);
        if ($avgRuns > 0) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgRuns, 1).' runs per game';
        }

        $avgHits = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->hits ?? 0);
        if ($avgHits >= 8) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgHits, 1).' hits per game';
        }

        return $messages;
    }
}
