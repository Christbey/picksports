<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class DriveEfficiencyTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'drive_efficiency';
    }

    public function collect(): array
    {
        $messages = [];

        $gamesWithStats = $this->games->filter(fn ($g) => $this->teamStats($g) !== null);

        if ($gamesWithStats->count() < 3) {
            return $messages;
        }

        $yardsPerPlay = $gamesWithStats->map(function ($game) {
            $stats = $this->teamStats($game);
            $yards = $stats->total_yards;
            if ($this->league === 'nfl') {
                if (! is_numeric($yards) || ! is_numeric($stats->rushing_attempts)
                    || ! is_numeric($stats->passing_attempts) || ! is_numeric($stats->sacks_allowed)) {
                    return null;
                }
                // NFL pass attempts exclude sacks; each sack is an offensive play.
                $plays = $stats->rushing_attempts + $stats->passing_attempts + $stats->sacks_allowed;
            } else {
                $plays = $stats->total_plays ?? ($stats->rush_attempts ?? 0) + ($stats->pass_attempts ?? 0);
            }

            return $plays > 0 ? $yards / $plays : 0;
        })->filter(fn ($v) => $v > 0);

        if ($yardsPerPlay->isNotEmpty()) {
            $avgYPP = $yardsPerPlay->avg();
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgYPP, 2).' yards per play';
        }

        $thirdDownGames = $gamesWithStats->filter(function ($game) {
            $stats = $this->teamStats($game);

            return is_numeric($stats->third_down_attempts) && $stats->third_down_attempts > 0
                && is_numeric($stats->third_down_conversions);
        });
        $thirdDownConversions = $thirdDownGames->filter(function ($game) {
            $stats = $this->teamStats($game);
            $attempts = $stats->third_down_attempts ?? 0;
            $conversions = $stats->third_down_conversions ?? 0;

            return $attempts > 0 && ($conversions / $attempts) >= 0.40;
        })->count();

        if ($thirdDownGames->isNotEmpty() && $this->isSignificant($thirdDownConversions, $thirdDownGames->count())) {
            $messages[] = "The {$this->teamAbbr} have converted 40%+ of 3rd downs in {$thirdDownConversions} of {$thirdDownGames->count()} games with recorded attempts";
        }

        $redZoneGames = $gamesWithStats->filter(function ($game) {
            $stats = $this->teamStats($game);

            return is_numeric($stats->red_zone_attempts) && $stats->red_zone_attempts >= 2
                && is_numeric($stats->red_zone_scores);
        });
        $redZoneEfficiency = $redZoneGames->filter(function ($game) {
            $stats = $this->teamStats($game);
            $attempts = $stats->red_zone_attempts ?? 0;
            $scores = $stats->red_zone_scores ?? 0;

            return $attempts >= 2 && $attempts > 0 && ($scores / $attempts) >= 0.75;
        })->count();

        if ($redZoneEfficiency >= 3) {
            $messages[] = "The {$this->teamAbbr} have had strong red zone efficiency (75%+) in {$redZoneEfficiency} of {$redZoneGames->count()} games with at least 2 recorded trips";
        }

        return $messages;
    }
}
