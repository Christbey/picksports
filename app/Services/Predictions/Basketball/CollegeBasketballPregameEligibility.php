<?php

namespace App\Services\Predictions\Basketball;

use App\Services\Sports\SportsDateWindowService;
use Illuminate\Database\Eloquent\Model;

class CollegeBasketballPregameEligibility
{
    public function teamsAreKnown(?Model $home, ?Model $away): bool
    {
        return ! $this->placeholder($home) && ! $this->placeholder($away);
    }

    public function identityMatches(Model $game): bool
    {
        $event = $game->sportEvent;
        $tipoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);

        return $event !== null && $tipoff !== null && $event->starts_at !== null
            && $tipoff->equalTo($event->starts_at) && (bool) $event->neutral_site === (bool) $game->neutral_site;
    }

    private function placeholder(?Model $team): bool
    {
        return $team === null
            || in_array(strtoupper(trim((string) $team->school)), ['TBD', 'TBD2'], true)
            || in_array(strtoupper(trim((string) $team->abbreviation)), ['TBD', 'TBD2', 'WFF', 'FF'], true)
            || (int) $team->espn_id < 0;
    }
}
