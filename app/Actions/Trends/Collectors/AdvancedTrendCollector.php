<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class AdvancedTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'advanced';
    }

    public function collect(): array
    {
        $messages = [];

        $gamesWithPrediction = $this->games->filter(fn ($g) => $g->relationLoaded('prediction') && $g->prediction);

        if ($gamesWithPrediction->isEmpty()) {
            return $messages;
        }

        $bigFavoriteGames = $gamesWithPrediction->filter(function ($game) {
            $spread = $game->prediction->predicted_spread ?? 0;
            $favoredAsHome = $this->isHome($game) && $spread <= -7;
            $favoredAsAway = ! $this->isHome($game) && $spread >= 7;

            return $favoredAsHome || $favoredAsAway;
        });

        if ($bigFavoriteGames->count() >= 2) {
            $bigFavoriteWins = $bigFavoriteGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($bigFavoriteWins, $bigFavoriteGames->count())} as big favorites (7+ point spread)";
        }

        $bigUnderdogGames = $gamesWithPrediction->filter(function ($game) {
            $spread = $game->prediction->predicted_spread ?? 0;
            $underdogAsHome = $this->isHome($game) && $spread >= 7;
            $underdogAsAway = ! $this->isHome($game) && $spread <= -7;

            return $underdogAsHome || $underdogAsAway;
        });

        if ($bigUnderdogGames->count() >= 2) {
            $bigUnderdogWins = $bigUnderdogGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($bigUnderdogWins, $bigUnderdogGames->count())} as big underdogs (7+ point spread)";
        }

        $covers = $gamesWithPrediction->filter(function ($game) {
            $spread = $game->prediction->predicted_spread ?? 0;
            $margin = $this->isHome($game)
                ? $game->home_score - $game->away_score
                : $game->away_score - $game->home_score;

            $adjustedSpread = $this->isHome($game) ? $spread : -$spread;

            return $margin > -$adjustedSpread;
        })->count();

        $total = $gamesWithPrediction->count();
        if ($total >= 5) {
            $coverPct = $this->percentage($covers, $total);
            $messages[] = "The {$this->teamAbbr} are {$covers}-".($total - $covers)." ({$coverPct}%) against the spread";
        }

        return $messages;
    }
}
