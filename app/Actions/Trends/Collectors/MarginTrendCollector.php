<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class MarginTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'margins';
    }

    public function collect(): array
    {
        $messages = [];
        $count = $this->games->count();
        $thresholds = $this->config('margin', [3, 7, 10, 14]);
        $unit = $this->scoringUnit();

        foreach ($thresholds as $threshold) {
            $winsBy = $this->countWhere(fn ($g) => $this->margin($g) >= $threshold);

            if ($this->isSignificant($winsBy)) {
                $messages[] = "The {$this->teamAbbr} have won by {$threshold}+ {$unit} in {$winsBy} of their last {$count} games";
            }
        }

        $closeMargin = $this->config('close_game_margin', $this->closeGameMargin());
        $closeGames = $this->games->filter(fn ($g) => abs($this->margin($g)) <= $closeMargin);

        if ($closeGames->count() >= 3) {
            $closeWins = $closeGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($closeWins, $closeGames->count())} in games decided by {$closeMargin} {$unit} or less";
        }

        $avgMargin = $this->games->avg(fn ($g) => $this->margin($g));
        $descriptor = $avgMargin >= 0 ? 'winning' : 'losing';
        $messages[] = "The {$this->teamAbbr} have an average {$descriptor} margin of ".number_format(abs($avgMargin), 1)." {$unit}";

        return $messages;
    }
}
