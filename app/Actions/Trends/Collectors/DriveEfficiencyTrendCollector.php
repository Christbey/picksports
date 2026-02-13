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
            $yards = $stats->total_yards ?? 0;
            $plays = $stats->total_plays ?? ($stats->rush_attempts ?? 0) + ($stats->pass_attempts ?? 0);

            return $plays > 0 ? $yards / $plays : 0;
        })->filter(fn ($v) => $v > 0);

        if ($yardsPerPlay->isNotEmpty()) {
            $avgYPP = $yardsPerPlay->avg();
            $messages[] = "The {$this->teamAbbr} average ".number_format($avgYPP, 2).' yards per play';
        }

        $thirdDownConversions = $gamesWithStats->filter(function ($game) {
            $stats = $this->teamStats($game);
            $attempts = $stats->third_down_attempts ?? 0;
            $conversions = $stats->third_down_conversions ?? 0;

            return $attempts > 0 && ($conversions / $attempts) >= 0.40;
        })->count();

        if ($this->isSignificant($thirdDownConversions, $gamesWithStats->count())) {
            $messages[] = "The {$this->teamAbbr} have converted 40%+ of 3rd downs in {$thirdDownConversions} of their last {$gamesWithStats->count()} games";
        }

        $redZoneEfficiency = $gamesWithStats->filter(function ($game) {
            $stats = $this->teamStats($game);
            $attempts = $stats->red_zone_attempts ?? 0;
            $scores = $stats->red_zone_scores ?? 0;

            return $attempts >= 2 && $attempts > 0 && ($scores / $attempts) >= 0.75;
        })->count();

        if ($redZoneEfficiency >= 3) {
            $messages[] = "The {$this->teamAbbr} have had strong red zone efficiency (75%+) in {$redZoneEfficiency} of their last {$gamesWithStats->count()} games";
        }

        return $messages;
    }
}
