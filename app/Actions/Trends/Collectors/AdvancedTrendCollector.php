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

        $gamesWithPrediction = $this->games->filter(fn ($g) => $this->modelTeamMargin($g) !== null);

        if ($gamesWithPrediction->isEmpty()) {
            return $messages;
        }

        $bigFavoriteGames = $gamesWithPrediction->filter(function ($game) {
            return $this->modelTeamMargin($game) >= 7;
        });

        if ($bigFavoriteGames->count() >= 2) {
            $bigFavoriteWins = $bigFavoriteGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->teamRecord($bigFavoriteGames)} when the model made them big favorites (7+ {$this->scoringUnit()} projected spread)";
        }

        $bigUnderdogGames = $gamesWithPrediction->filter(function ($game) {
            return $this->modelTeamMargin($game) <= -7;
        });

        if ($bigUnderdogGames->count() >= 2) {
            $bigUnderdogWins = $bigUnderdogGames->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->teamRecord($bigUnderdogGames)} when the model made them big underdogs (7+ {$this->scoringUnit()} projected spread)";
        }

        $covers = $gamesWithPrediction->filter(function ($game) {
            return $this->margin($game) > $this->modelTeamMargin($game);
        })->count();

        $total = $gamesWithPrediction->count();
        if ($total >= 5) {
            $coverPct = $this->percentage($covers, $total);
            $pushes = $gamesWithPrediction->filter(fn ($g) => abs($this->margin($g) - $this->modelTeamMargin($g)) < 0.00001)->count();
            $record = $this->formatRecord($covers, $total, $pushes);
            $messages[] = "The {$this->teamAbbr} are {$record} against the model spread (not market ATS)";
        }

        return $messages;
    }
}
