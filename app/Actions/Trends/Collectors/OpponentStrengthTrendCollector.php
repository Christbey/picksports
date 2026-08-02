<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class OpponentStrengthTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'opponent_strength';
    }

    public function collect(): array
    {
        $messages = [];

        $gamesWithPregameElo = $this->games->filter(
            fn ($game): bool => $this->opponentPregameElo($game) !== null
        );
        $gamesVsTopElo = $gamesWithPregameElo->filter(
            fn ($game): bool => $this->opponentPregameElo($game) >= 1550
        );

        if ($gamesVsTopElo->count() >= 3) {
            $winsVsTop = $gamesVsTopElo->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($winsVsTop, $gamesVsTopElo->count())} against opponents with pregame Elo 1550+";
        }

        $gamesVsBelowAverageElo = $gamesWithPregameElo->filter(
            fn ($game): bool => $this->opponentPregameElo($game) < 1500
        );

        if ($gamesVsBelowAverageElo->count() >= 3) {
            $wins = $gamesVsBelowAverageElo->filter(fn ($game): bool => $this->won($game))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($wins, $gamesVsBelowAverageElo->count())} against opponents with pregame Elo below 1500";
        }

        return $messages;
    }

    protected function opponentPregameElo(object $game): ?float
    {
        if (! $game->relationLoaded('prediction') || ! $game->prediction) {
            return null;
        }

        $value = $this->isHome($game)
            ? $game->prediction->away_team_elo
            : $game->prediction->home_team_elo;

        return is_numeric($value) ? (float) $value : null;
    }
}
