<?php

namespace App\Actions\OddsApi;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractCollegeBasketballSyncOddsForGames extends AbstractSyncOddsForGames
{
    protected function matchThreshold(): float
    {
        return 85.0;
    }

    protected function homeTeamNames(Model $game): array
    {
        return $this->collegeBasketballTeamNames($game->homeTeam);
    }

    protected function awayTeamNames(Model $game): array
    {
        return $this->collegeBasketballTeamNames($game->awayTeam);
    }

    /**
     * @return array<int,string>
     */
    protected function collegeBasketballTeamNames(mixed $team): array
    {
        return array_values(array_filter(array_unique(array_merge(
            $this->schoolMascotAbbreviationTeamNames($team),
            [
                trim((string) (($team->school ?? '').' '.($team->mascot ?? ''))),
                (string) ($team->display_name ?? ''),
            ]
        ))));
    }

    protected function localGamesQuery(string $oddsSportKey, ?int $daysAhead = null): Builder
    {
        return parent::localGamesQuery($oddsSportKey, $daysAhead)
            ->where(function (Builder $query) {
                $query->whereNull('espn_event_id')
                    ->orWhere('espn_event_id', 'not like', 'placeholder:%');
            })
            ->whereHas('homeTeam', fn (Builder $teamQuery) => $this->scopeRealTeams($teamQuery))
            ->whereHas('awayTeam', fn (Builder $teamQuery) => $this->scopeRealTeams($teamQuery));
    }

    private function scopeRealTeams(Builder $query): void
    {
        $query
            ->whereNotIn('school', ['TBD', 'TBD1', 'TBD2'])
            ->whereNotIn('abbreviation', ['TBD', 'TBD1', 'TBD2', 'WFF', 'FF'])
            ->where('espn_id', '>=', 0);
    }
}
