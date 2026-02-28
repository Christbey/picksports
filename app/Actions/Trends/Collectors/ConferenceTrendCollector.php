<?php

namespace App\Actions\Trends\Collectors;

use App\Actions\Trends\TrendCollector;

class ConferenceTrendCollector extends TrendCollector
{
    public function key(): string
    {
        return 'conference';
    }

    public function collect(): array
    {
        $messages = [];
        $teamConference = $this->team->conference ?? null;
        $teamDivision = $this->team->division ?? null;

        if ($teamConference) {
            $conferenceGames = $this->games->filter(function ($game) use ($teamConference) {
                $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;

                return $opponent && ($opponent->conference ?? null) === $teamConference;
            });

            if ($conferenceGames->count() >= 3) {
                $conferenceWins = $conferenceGames->filter(fn ($g) => $this->won($g))->count();
                $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($conferenceWins, $conferenceGames->count())} in conference games";
            }

            $nonConferenceGames = $this->games->filter(function ($game) use ($teamConference) {
                $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;

                return $opponent && ($opponent->conference ?? null) !== $teamConference;
            });

            if ($nonConferenceGames->count() >= 3) {
                $nonConferenceWins = $nonConferenceGames->filter(fn ($g) => $this->won($g))->count();
                $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($nonConferenceWins, $nonConferenceGames->count())} in non-conference games";
            }
        }

        if ($teamDivision && ! $this->isCollegeBasketball()) {
            $divisionGames = $this->games->filter(function ($game) use ($teamDivision) {
                $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;

                return $opponent && ($opponent->division ?? null) === $teamDivision;
            });

            if ($divisionGames->count() >= 2) {
                $divisionWins = $divisionGames->filter(fn ($g) => $this->won($g))->count();
                $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($divisionWins, $divisionGames->count())} in division games";
            }
        }

        return $messages;
    }
}
