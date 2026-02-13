<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class StreakTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'streaks';
    }

    public function collect(): array
    {
        $messages = [];

        $winStreak = $this->calculateStreak(fn ($g) => $this->won($g));
        if ($winStreak >= 3) {
            $messages[] = "The {$this->teamAbbr} are on a {$winStreak}-game winning streak";
        }

        $lossStreak = $this->calculateStreak(fn ($g) => ! $this->won($g));
        if ($lossStreak >= 3) {
            $messages[] = "The {$this->teamAbbr} are on a {$lossStreak}-game losing streak";
        }

        $homeGames = $this->games->filter(fn ($g) => $this->isHome($g));
        if ($homeGames->count() >= 3) {
            $homeWins = $homeGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($homeWins, $homeGames->count())} at home in their last {$homeGames->count()} home games";
        }

        $awayGames = $this->games->filter(fn ($g) => ! $this->isHome($g));
        if ($awayGames->count() >= 3) {
            $awayWins = $awayGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($awayWins, $awayGames->count())} on the road in their last {$awayGames->count()} road games";
        }

        $atsStreak = $this->calculateATSStreak();
        if (abs($atsStreak) >= 3) {
            $type = $atsStreak > 0 ? 'ATS covers' : 'ATS misses';
            $messages[] = "The {$this->teamAbbr} are on a ".abs($atsStreak)."-game {$type} streak";
        }

        return $messages;
    }

    protected function calculateATSStreak(): int
    {
        $streak = 0;
        $direction = null;

        foreach ($this->games->sortByDesc('game_date') as $game) {
            if (! $game->relationLoaded('prediction') || ! $game->prediction) {
                continue;
            }

            $spread = $game->prediction->predicted_spread ?? null;
            if ($spread === null) {
                continue;
            }

            $actualMargin = $this->isHome($game)
                ? $game->home_score - $game->away_score
                : $game->away_score - $game->home_score;

            $adjustedMargin = $this->isHome($game) ? $actualMargin : -$actualMargin;
            $covered = $adjustedMargin > -$spread;

            if ($direction === null) {
                $direction = $covered;
                $streak = 1;
            } elseif ($covered === $direction) {
                $streak++;
            } else {
                break;
            }
        }

        return $direction ? $streak : -$streak;
    }
}
