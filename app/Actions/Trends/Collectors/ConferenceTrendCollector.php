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
        $teamConference = $this->teamConferenceValue();
        $teamDivision = $this->teamDivisionValue();
        $sameGroupLabel = $this->isBaseball() ? 'league' : 'conference';
        $otherGroupLabel = $this->isBaseball() ? 'interleague' : 'non-conference';

        if ($teamConference) {
            $conferenceGames = $this->games->filter(function ($game) use ($teamConference) {
                $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;

                return $opponent && $this->opponentConferenceValue($opponent) === $teamConference;
            });

            if ($conferenceGames->count() >= 3) {
                $conferenceWins = $conferenceGames->filter(fn ($g) => $this->won($g))->count();
                $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($conferenceWins, $conferenceGames->count())} in {$sameGroupLabel} games";
            }

            $nonConferenceGames = $this->games->filter(function ($game) use ($teamConference) {
                $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;

                return $opponent && $this->opponentConferenceValue($opponent) !== $teamConference;
            });

            if ($nonConferenceGames->count() >= 3) {
                $nonConferenceWins = $nonConferenceGames->filter(fn ($g) => $this->won($g))->count();
                $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($nonConferenceWins, $nonConferenceGames->count())} in {$otherGroupLabel} games";
            }
        }

        if ($teamDivision && ! $this->isCollegeBasketball()) {
            $divisionGames = $this->games->filter(function ($game) use ($teamDivision) {
                $opponent = $this->isHome($game) ? $game->awayTeam : $game->homeTeam;

                return $opponent && $this->opponentDivisionValue($opponent) === $teamDivision;
            });

            if ($divisionGames->count() >= 2) {
                $divisionWins = $divisionGames->filter(fn ($g) => $this->won($g))->count();
                $messages[] = "The {$this->teamAbbr} are {$this->formatRecord($divisionWins, $divisionGames->count())} in division games";
            }
        }

        return $messages;
    }

    protected function teamConferenceValue(): ?string
    {
        if (! $this->isBaseball()) {
            return $this->team->conference ?? null;
        }

        return $this->team->league ?? $this->mlbAlignmentValue($this->team, 'league');
    }

    protected function opponentConferenceValue(object $opponent): ?string
    {
        if (! $this->isBaseball()) {
            return $opponent->conference ?? null;
        }

        return $opponent->league ?? $this->mlbAlignmentValue($opponent, 'league');
    }

    protected function teamDivisionValue(): ?string
    {
        return $this->team->division ?? $this->mlbAlignmentValue($this->team, 'division');
    }

    protected function opponentDivisionValue(object $opponent): ?string
    {
        return $opponent->division ?? $this->mlbAlignmentValue($opponent, 'division');
    }

    protected function mlbAlignmentValue(object $team, string $field): ?string
    {
        if (! $this->isBaseball()) {
            return null;
        }

        $abbr = strtoupper((string) ($team->abbreviation ?? ''));
        if ($abbr === '') {
            return null;
        }

        return config("mlb.teams.alignment.{$abbr}.{$field}");
    }
}
