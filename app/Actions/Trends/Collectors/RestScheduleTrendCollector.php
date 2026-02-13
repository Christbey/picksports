<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;
use Carbon\Carbon;

class RestScheduleTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'rest_schedule';
    }

    public function collect(): array
    {
        $messages = [];
        $shortRestDays = $this->config('short_rest_days', 6);

        $sortedGames = $this->games->sortBy('game_date')->values();
        $shortRestGames = collect();
        $longRestGames = collect();

        foreach ($sortedGames as $index => $game) {
            if ($index === 0) {
                continue;
            }

            $prevGame = $sortedGames[$index - 1];
            $restDays = $this->calculateRestDays($prevGame, $game);

            if ($restDays !== null) {
                if ($restDays <= $shortRestDays) {
                    $shortRestGames->push($game);
                } else {
                    $longRestGames->push($game);
                }
            }
        }

        if ($shortRestGames->count() >= 3) {
            $shortRestWins = $shortRestGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($shortRestWins, $shortRestGames->count())} on short rest ({$shortRestDays} days or less)";
        }

        if ($longRestGames->count() >= 3) {
            $longRestWins = $longRestGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($longRestWins, $longRestGames->count())} on extended rest (more than {$shortRestDays} days)";
        }

        return $messages;
    }

    protected function calculateRestDays(object $prevGame, object $currentGame): ?int
    {
        try {
            $prevDate = Carbon::parse($prevGame->game_date);
            $currentDate = Carbon::parse($currentGame->game_date);

            return $currentDate->diffInDays($prevDate);
        } catch (\Exception) {
            return null;
        }
    }
}
