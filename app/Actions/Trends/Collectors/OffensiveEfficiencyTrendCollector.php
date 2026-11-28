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

        $avgYards = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->total_yards);
        if ($avgYards > 0) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgYards, 1).' total yards per game';
        }

        $pairedYards = $gamesWithStats->filter(fn ($g) => is_numeric($this->teamStats($g)->passing_yards)
            && is_numeric($this->teamStats($g)->rushing_yards));
        $avgPassYards = $pairedYards->avg(fn ($g) => $this->teamStats($g)->passing_yards);
        $avgRushYards = $pairedYards->avg(fn ($g) => $this->teamStats($g)->rushing_yards);

        if ($avgPassYards > 0 && $avgRushYards > 0) {
            if ($avgPassYards > $avgRushYards * 1.5) {
                $messages[] = "The {$this->teamAbbr} are pass-heavy, averaging ".number_format($avgPassYards, 1).' passing yards vs '.number_format($avgRushYards, 1).' rushing yards';
            } elseif ($avgRushYards > $avgPassYards * 0.8) {
                $messages[] = "The {$this->teamAbbr} have a balanced attack with ".number_format($avgPassYards, 1).' passing and '.number_format($avgRushYards, 1).' rushing yards per game';
            }
        }

        $avgFirstDowns = $gamesWithStats->avg(fn ($g) => $this->teamStats($g)->first_downs);
        if ($avgFirstDowns >= 15) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgFirstDowns, 1).' first downs per game';
        }

        $turnoverGames = $gamesWithStats->filter(function ($g) {
            $stats = $this->teamStats($g);

            return is_numeric($stats->interceptions_thrown ?? $stats->interceptions)
                && is_numeric($stats->fumbles_lost);
        });
        $turnoversLow = $turnoverGames->filter(function ($g) {
            $stats = $this->teamStats($g);
            $interceptions = $stats->interceptions_thrown ?? $stats->interceptions ?? 0;
            $turnovers = ($stats->fumbles_lost ?? 0) + $interceptions;

            return $turnovers <= 1;
        })->count();

        if ($turnoverGames->isNotEmpty() && $this->isSignificant($turnoversLow, $turnoverGames->count())) {
            $messages[] = "The {$this->teamAbbr} have had 1 or fewer turnovers in {$turnoversLow} of {$turnoverGames->count()} games with recorded turnover stats";
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
            $points = $stats->points;
            $possessions = $stats->possessions;

            if (! $this->isRecordedCount($points) || ! $this->isRecordedCount($possessions)) {
                return null;
            }

            if ($possessions <= 0) {
                return null;
            }

            $rating = ($points / $possessions) * 100;

            return is_finite($rating) ? $rating : null;
        })->filter(fn ($value) => $value !== null);

        if ($efficiencyValues->isNotEmpty() && is_finite($efficiencyValues->avg())) {
            $avgOffEff = $efficiencyValues->avg();
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgOffEff, 1).' offensive rating (points per 100 possessions)';
        }

        $turnoverGames = $gamesWithStats->filter(fn ($game) => $this->isRecordedCount($this->teamStats($game)->turnovers));
        $turnoversLow = $turnoverGames->filter(function ($game) {
            $stats = $this->teamStats($game);
            $turnovers = (float) $stats->turnovers;

            return $turnovers <= 12;
        })->count();

        if ($turnoverGames->isNotEmpty() && $this->isSignificant($turnoversLow, $turnoverGames->count())) {
            $sample = $turnoverGames->count() === $gamesWithStats->count()
                ? "their last {$turnoverGames->count()} games"
                : "{$turnoverGames->count()} games with recorded turnover stats";
            $messages[] = "The {$this->teamAbbr} have committed 12 or fewer turnovers in {$turnoversLow} of {$sample}";
        }

        $assistTurnoverRatios = $gamesWithStats->map(function ($game) {
            $stats = $this->teamStats($game);
            $assists = $stats->assists;
            $turnovers = $stats->turnovers;

            if (! $this->isRecordedCount($assists) || ! $this->isRecordedCount($turnovers)) {
                return null;
            }

            if ($turnovers <= 0) {
                return null;
            }

            $ratio = $assists / $turnovers;

            return is_finite($ratio) ? $ratio : null;
        })->filter(fn ($ratio) => $ratio !== null);

        if ($assistTurnoverRatios->isNotEmpty()) {
            $avgAstTo = $assistTurnoverRatios->avg();
            if (is_finite($avgAstTo) && $avgAstTo >= 1.4) {
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

        $avgRuns = $gamesWithStats->map(fn ($g) => $this->teamStats($g)->runs)
            ->filter(fn ($value) => $this->isRecordedCount($value))->avg();
        if ($avgRuns !== null && is_finite($avgRuns) && $avgRuns > 0) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgRuns, 1).' runs per game';
        }

        $avgHits = $gamesWithStats->map(fn ($g) => $this->teamStats($g)->hits)
            ->filter(fn ($value) => $this->isRecordedCount($value))->avg();
        if ($avgHits !== null && is_finite($avgHits) && $avgHits >= 8) {
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgHits, 1).' hits per game';
        }

        return $messages;
    }

    private function isRecordedCount(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0;
    }
}
