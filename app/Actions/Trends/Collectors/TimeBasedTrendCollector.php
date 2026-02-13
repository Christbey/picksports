<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;
use Carbon\Carbon;

class TimeBasedTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'time_based';
    }

    public function collect(): array
    {
        $messages = [];
        $earlyHour = $this->config('early_game_hour', 13);
        $lateHour = $this->config('late_game_hour', 20);

        $earlyGames = $this->games->filter(function ($game) use ($earlyHour) {
            $hour = $this->getGameHour($game);

            return $hour !== null && $hour <= $earlyHour;
        });

        if ($earlyGames->count() >= 3) {
            $earlyWins = $earlyGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($earlyWins, $earlyGames->count())} in early games (before {$earlyHour}:00)";
        }

        $lateGames = $this->games->filter(function ($game) use ($lateHour) {
            $hour = $this->getGameHour($game);

            return $hour !== null && $hour >= $lateHour;
        });

        if ($lateGames->count() >= 3) {
            $lateWins = $lateGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($lateWins, $lateGames->count())} in late/night games (after {$lateHour}:00)";
        }

        return $messages;
    }

    protected function getGameHour(object $game): ?int
    {
        if (empty($game->game_time)) {
            return null;
        }

        try {
            $time = Carbon::parse($game->game_time);

            return $time->hour;
        } catch (\Exception) {
            return null;
        }
    }
}
