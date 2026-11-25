<?php

namespace App\Actions\CBB;

use App\Actions\Sports\Concerns\CalculatesTeamTrueEpaFromPlays;
use App\Concerns\FiltersTeamGames;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;

class RefreshTeamPlayEpa
{
    use CalculatesTeamTrueEpaFromPlays;
    use FiltersTeamGames;

    public function execute(Team $team, int $season): bool
    {
        $games = $this->getCompletedGamesForTeam($team, $season, 'CBB');
        if ($games->isEmpty()) {
            return false;
        }
        foreach ($games as $game) {
            if (! $game->teamStats->contains('team_id', $game->home_team_id)
                || ! $game->teamStats->contains('team_id', $game->away_team_id)) {
                return false;
            }
        }
        $values = $this->calculateTeamTrueEpaMetrics(Play::class, (int) $team->id, $games);
        $values = array_map(fn (?float $value): ?float => $this->roundOrNull($value, 3), $values);

        return TeamMetric::query()->where('team_id', $team->id)->where('season', $season)
            ->update([...$values, 'updated_at' => now()]) > 0;
    }
}
