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
            $messages[] = "The {$this->teamAbbr} are {$this->teamRecord($gamesVsTopElo)} against opponents with pregame Elo 1550+";
        }

        $gamesVsBelowAverageElo = $gamesWithPregameElo->filter(
            fn ($game): bool => $this->opponentPregameElo($game) < 1500
        );

        if ($gamesVsBelowAverageElo->count() >= 3) {
            $messages[] = "The {$this->teamAbbr} are {$this->teamRecord($gamesVsBelowAverageElo)} against opponents with pregame Elo below 1500";
        }

        return $messages;
    }

    protected function opponentPregameElo(object $game): ?float
    {
        $forecast = $this->forecast($game);

        return $this->isHome($game) ? $forecast?->awayTeamElo : $forecast?->homeTeamElo;
    }
}
