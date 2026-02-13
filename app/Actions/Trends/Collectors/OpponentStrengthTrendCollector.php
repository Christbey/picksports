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

        $gamesVsWinning = $this->games->filter(function ($game) {
            $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;

            return $opponent && $this->hasWinningRecord($opponent);
        });

        if ($gamesVsWinning->count() >= 3) {
            $winsVsWinning = $gamesVsWinning->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($winsVsWinning, $gamesVsWinning->count())} against winning teams";
        }

        $gamesVsLosing = $this->games->filter(function ($game) {
            $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;

            return $opponent && ! $this->hasWinningRecord($opponent);
        });

        if ($gamesVsLosing->count() >= 3) {
            $winsVsLosing = $gamesVsLosing->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($winsVsLosing, $gamesVsLosing->count())} against losing teams";
        }

        $gamesVsTopElo = $this->games->filter(function ($game) {
            $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;
            $opponentElo = $opponent->elo_rating ?? 1500;

            return $opponentElo >= 1550;
        });

        if ($gamesVsTopElo->count() >= 3) {
            $winsVsTop = $gamesVsTopElo->filter(fn ($g) => $this->won($g))->count();
            $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($winsVsTop, $gamesVsTopElo->count())} against high-rated opponents (Elo 1550+)";
        }

        return $messages;
    }

    protected function hasWinningRecord(object $team): bool
    {
        $wins = $team->wins ?? 0;
        $losses = $team->losses ?? 0;

        if ($wins + $losses === 0) {
            return ($team->elo_rating ?? 1500) >= 1510;
        }

        return $wins > $losses;
    }
}
